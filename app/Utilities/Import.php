<?php

namespace App\Utilities;

use App\Abstracts\Import as AbstractsImport;
use App\Abstracts\ImportMultipleSheets;
use App\Jobs\Auth\NotifyUser;
use App\Notifications\Common\ImportCompleted;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Exceptions\SheetNotFoundException;
use Maatwebsite\Excel\Validators\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class Import
{
    /**
     * Import the excel file or catch errors
     *
     * @param AbstractsImport|ImportMultipleSheets $class
     *
     * @return array
     */
    public static function fromExcel($class, Request $request, string $translation): array
    {
        $success = true;

        try {
            $should_queue = should_queue();

            $file = $request->file('import');

            // Checked upfront because the reader fails deep inside on a missing sheet.
            if (self::hasMissingSheets($class, $file)) {
                return [
                    'success'   => false,
                    'error'     => true,
                    'data'      => null,
                    'message'   => trans('messages.error.import_sheet'),
                ];
            }

            if ($should_queue) {
                self::importQueue($class, $file, $translation);
            } else {
                $class->import($file);
            }

            $message = trans(
                'messages.success.' . ($should_queue ? 'import_queued' : 'imported'),
                ['type' => $translation]
            );
        } catch (Throwable $e) {
            if (! $e instanceof SheetNotFoundException) {
                report($e);
            }

            $message = self::flashFailures($e);

            $success = false;
        }

        return [
            'success'   => $success,
            'error'     => ! $success,
            'data'      => null,
            'message'   => $message,
        ];
    }

    /**
     * Whether the file is missing a sheet the import declares.
     *
     * @param AbstractsImport|ImportMultipleSheets $class
     */
    protected static function hasMissingSheets($class, $file): bool
    {
        if (! $class instanceof ImportMultipleSheets) {
            return false;
        }

        try {
            $path = $file->getRealPath();

            $reader = IOFactory::createReaderForFile($path);

            // A csv has no worksheets to compare against.
            if (! method_exists($reader, 'listWorksheetNames')) {
                return false;
            }

            $names = $reader->listWorksheetNames($path);
        } catch (Throwable $e) {
            // The file type could not be read here, so leave it to the import itself.
            return false;
        }

        return ! empty(array_diff(array_keys($class->sheets()), $names));
    }

    /**
     * Import the excel file
     *
     * @param AbstractsImport|ImportMultipleSheets $class
     */
    protected static function importQueue($class, $file, string $translation): void
    {
        $rows = $class->toArray($file);

        $total_rows = 0;

        if (! empty($rows[0])) {
            $total_rows = count($rows[0]);
        } else if ($class instanceof ImportMultipleSheets && ! empty($sheets = $class->sheets())) {
            $total_rows = count($rows[array_keys($sheets)[0]] ?? []);
        }

        $class->queue($file)->onQueue('imports')->chain([
            new NotifyUser(user(), new ImportCompleted($translation, $total_rows))
        ]);
    }

    protected static function flashFailures(Throwable $e): string
    {
        if (! $e instanceof ValidationException) {
            return $e->getMessage();
        }

        foreach ($e->failures() as $failure) {
            $message = trans('messages.error.import_column', [
                'message'   => collect($failure->errors())->first(),
                'column'    => $failure->attribute(),
                'line'      => $failure->row(),
            ]);

            flash($message)->error()->important();
        }

        return '';
    }
}
