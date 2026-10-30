<?php

namespace Tests\Unit\Traits;

use App\Models\Banking\Transaction;
use App\Models\Banking\Transfer;
use App\Models\Document\Document;
use App\Traits\SearchString;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * Covers stripUnknownSearchStringTokens() against the real search-string
 * config. Columns may be declared either as 'name' => [options] or as a plain
 * 'name' without options; both forms are queryable, so both must survive.
 */
class StripUnknownSearchStringTokensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The method only needs config(), so bind a config repository holding
        // the real file instead of booting the whole framework.
        $container = new Container();

        $container->instance('config', new Repository([
            'search-string' => require dirname(__DIR__, 3) . '/config/search-string.php',
        ]));

        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    protected function strip(string $input, string $model_class): string
    {
        $probe = new class {
            use SearchString;
        };

        return $probe->stripUnknownSearchStringTokens($input, $model_class);
    }

    /**
     * Regression for the REST filter breaking on every column that was
     * declared without options.
     */
    public function testItKeepsColumnsDeclaredWithoutOptions(): void
    {
        foreach (['id:5', 'amount:100', 'document_id:3', 'payment_method:cash', 'parent_id:1'] as $token) {
            $this->assertSame($token, $this->strip($token, Transaction::class), $token . ' was stripped');
        }
    }

    public function testItKeepsTheQuotedNumberFilterFromTheIssue(): void
    {
        $this->assertSame(
            'number:"txn_1"',
            $this->strip('number:"txn_1"', Transaction::class)
        );
    }

    public function testItKeepsColumnsDeclaredWithOptions(): void
    {
        $this->assertSame('type:income', $this->strip('type:income', Transaction::class));
        $this->assertSame('account_id:9', $this->strip('account_id:9', Transaction::class));
    }

    public function testItKeepsRelationshipTokens(): void
    {
        $this->assertSame('recurring.id:1', $this->strip('recurring.id:1', Document::class));
        $this->assertSame('expense_account.id:1', $this->strip('expense_account.id:1', Transfer::class));
    }

    public function testItKeepsKeyAliasesMatchedByARegexPattern(): void
    {
        $this->assertSame(
            'invoiced_at>=2026-01-01',
            $this->strip('invoiced_at>=2026-01-01', Document::class)
        );
    }

    public function testItKeepsDateRangesAndDefaultKeywords(): void
    {
        $this->assertSame(
            'paid_at>=2026-01-01 paid_at<=2026-01-31',
            $this->strip('paid_at>=2026-01-01 paid_at<=2026-01-31', Transaction::class)
        );

        $this->assertSame(
            'sort:paid_at limit:10 page:2',
            $this->strip('sort:paid_at limit:10 page:2', Transaction::class)
        );
    }

    public function testItStripsUnknownTokens(): void
    {
        $this->assertSame('', $this->strip('foo:bar', Transaction::class));
    }

    /**
     * Reports handle these themselves, so they must never reach the builder.
     */
    public function testItStripsReportControlTokens(): void
    {
        $this->assertSame('', $this->strip('basis:accrual period:quarterly', Transaction::class));

        $this->assertSame(
            'type:income',
            $this->strip('basis:accrual type:income period:quarterly', Transaction::class)
        );
    }

    public function testItKeepsTheNotPrefixOnKnownColumns(): void
    {
        $this->assertSame('not number:TX-1', $this->strip('not number:TX-1', Transaction::class));
        $this->assertSame('not amount>100', $this->strip('not amount>100', Transaction::class));
    }

    public function testItLeavesBareSearchTermsAlone(): void
    {
        $this->assertSame('Some free text', $this->strip('Some free text', Transaction::class));
    }

    public function testItReturnsTheInputUnchangedForModelsWithoutConfig(): void
    {
        $this->assertSame(
            'anything:goes',
            $this->strip('anything:goes', 'Modules\\Example\\Models\\Thing')
        );
    }
}
