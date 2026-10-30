<?php

namespace Tests\Unit\Traits;

use App\Traits\SearchString;
use PHPUnit\Framework\TestCase;

/**
 * Covers the documented contract of getSearchStringValue():
 * ':' and '=' are single-value operators returning a string, while
 * '>', '<', '>=' and '<=' are range operators returning an array.
 */
class SearchStringValueTest extends TestCase
{
    protected function probe(): object
    {
        return new class {
            use SearchString;
        };
    }

    public function testItReturnsAStringForTheColonOperator(): void
    {
        $this->assertSame('income', $this->probe()->getSearchStringValue('type', '', 'type:income'));
    }

    public function testItReturnsAStringForTheEqualsOperator(): void
    {
        $this->assertSame('income', $this->probe()->getSearchStringValue('type', '', 'type=income'));
    }

    public function testItReturnsAStringForSpacedOperators(): void
    {
        $this->assertSame('income', $this->probe()->getSearchStringValue('type', '', 'type : income'));
        $this->assertSame('income', $this->probe()->getSearchStringValue('type', '', 'type = income'));
    }

    public function testItKeepsCommaSeparatedValuesAsASingleString(): void
    {
        $this->assertSame('paid,partial', $this->probe()->getSearchStringValue('status', '', 'status:paid,partial'));
        $this->assertSame('paid,partial', $this->probe()->getSearchStringValue('status', '', 'status=paid,partial'));
    }

    public function testItReturnsAnArrayForARange(): void
    {
        $this->assertSame(
            ['2026-01-01', '2026-01-31'],
            $this->probe()->getSearchStringValue('paid_at', '', 'paid_at>=2026-01-01 paid_at<=2026-01-31')
        );
    }

    /**
     * Regression: the operator must be read from its position, not by looking
     * for a colon anywhere in the token. A time in the value used to make the
     * range return a string and silently break date filters.
     */
    public function testItReturnsAnArrayForARangeWhoseValueContainsAColon(): void
    {
        $this->assertSame(
            ['2026-01-01T10:30:00'],
            $this->probe()->getSearchStringValue('paid_at', '', 'paid_at>=2026-01-01T10:30:00')
        );
    }

    public function testItAccumulatesRangesRegardlessOfOperator(): void
    {
        $this->assertSame(
            ['100', '500'],
            $this->probe()->getSearchStringValue('amount', '', 'amount>100 amount<500')
        );
    }

    public function testItIgnoresTheNotPrefix(): void
    {
        $this->assertSame(['100'], $this->probe()->getSearchStringValue('amount', '', 'not amount>100'));
        $this->assertSame('100', $this->probe()->getSearchStringValue('amount', '', 'not amount:100'));
    }

    public function testItPicksTheRequestedColumnOutOfAMixedString(): void
    {
        $this->assertSame(
            'income',
            $this->probe()->getSearchStringValue('type', '', 'account_id:9 type:income paid_at>=2026-01-01')
        );
    }

    public function testItDoesNotMatchColumnsThatMerelyShareAPrefix(): void
    {
        $this->assertSame('', $this->probe()->getSearchStringValue('type', '', 'type_id:5'));
        $this->assertSame('', $this->probe()->getSearchStringValue('type', '', 'contact_type:vendor'));
    }

    public function testItReturnsTheDefaultWhenTheColumnIsAbsentOrEmpty(): void
    {
        $this->assertSame('accrual', $this->probe()->getSearchStringValue('basis', 'accrual', 'type:income'));
        $this->assertSame('accrual', $this->probe()->getSearchStringValue('basis', 'accrual', 'basis:'));
    }

    public function testItStripsSurroundingQuotesFromValues(): void
    {
        $this->assertSame('txn_1', $this->probe()->getSearchStringValue('number', '', 'number:"txn_1"'));
    }

    /**
     * The input is split on spaces before the quoted term is matched, so only
     * a single-word quoted term is recognised today.
     */
    public function testItReturnsBareQuotedTermsForTheSearchableName(): void
    {
        $this->assertSame('John', $this->probe()->getSearchStringValue('searchable', '', '"John"'));
    }

    public function testItMapsOperatorsForEloquent(): void
    {
        $probe = $this->probe();

        $this->assertSame('=', $probe->getSearchStringOperator('amount', '=', 'amount:100'));
        $this->assertSame('=', $probe->getSearchStringOperator('amount', '=', 'amount=100'));
        $this->assertSame('>=', $probe->getSearchStringOperator('amount', '=', 'amount>=100'));
        $this->assertSame('<', $probe->getSearchStringOperator('amount', '=', 'not amount>=100'));
        $this->assertSame('!=', $probe->getSearchStringOperator('amount', '=', 'not amount:100'));
    }
}
