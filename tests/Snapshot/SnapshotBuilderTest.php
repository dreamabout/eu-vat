<?php

declare(strict_types=1);

namespace Kaikei\EuVat\Tests\Snapshot;

use Kaikei\EuVat\Snapshot\SnapshotBuilder;
use Kaikei\EuVat\Snapshot\SnapshotWriter;
use Kaikei\EuVat\Tedb\ResponseParser;
use Kaikei\EuVat\Territory\TerritoryTable;
use PHPUnit\Framework\TestCase;

final class SnapshotBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function build(string $generatedAt = '2026-09-15T00:00:00Z'): array
    {
        $parsed = (new ResponseParser())->parse(file_get_contents(__DIR__.'/../fixtures/tedb-eu27-current.xml'));
        $parsed = $parsed->classifyTerritories(TerritoryTable::fromFile(__DIR__.'/../../data/territories.json'));

        return (new SnapshotBuilder())->build(
            $parsed,
            new \DateTimeImmutable('2026-09-15'),
            new \DateTimeImmutable('2027-12-31'),
            $generatedAt,
        );
    }

    /**
     * Regression. TEDB reports Jungholz and Mittelberg as an Austrian REDUCED row of 19%, with
     * the bare comment "Jungholz, Mittelberg" — no "VAT - X - " wrapper, so the shape rule alone
     * misses it. Published unclassified, it reads as though Austria has a 19% reduced band that
     * anyone can charge. It does not; 19% applies in two alpine exclaves of a few thousand people.
     */
    public function testAnAlpineExclaveDoesNotBecomeAnAustrianReducedRate(): void
    {
        $snapshot = $this->build();

        $austrianReduced = array_column($snapshot['countries']['AT']['reduced'], 'percent');

        self::assertNotContains('19.00', $austrianReduced, 'Jungholz/Mittelberg leaked into Austria\'s reduced bands.');
        self::assertContains('10.00', $austrianReduced, 'Austria\'s real reduced rate must survive.');

        $observed = array_column($snapshot['territorial_observed']['AT'] ?? [], 'qualifier');
        self::assertContains('Jungholz and Mittelberg', $observed, 'It must be kept as evidence.');
    }

    public function testTheCanaryRateDoesNotBecomeASpanishRate(): void
    {
        $snapshot = $this->build();

        self::assertSame(['21.00'], array_column($snapshot['countries']['ES']['standard'], 'percent'));
        self::assertNotContains('7.00', array_column($snapshot['countries']['ES']['reduced'], 'percent'));
    }

    public function testEveryMemberStateIsPresent(): void
    {
        self::assertCount(27, $this->build()['countries']);
    }

    public function testOutputIsByteIdenticalForIdenticalInput(): void
    {
        $writer = new SnapshotWriter();

        self::assertSame($writer->encode($this->build()), $writer->encode($this->build()));
    }

    public function testCountriesAreSortedSoDiffsStaySmall(): void
    {
        $countries = array_keys($this->build()['countries']);
        $sorted = $countries;
        sort($sorted);

        self::assertSame($sorted, $countries);
    }

    /**
     * The property the whole nightly-job design rests on: a run that changes only the timestamp
     * must not look like a rate change, or the job commits every night and the signal is worth
     * nothing.
     */
    public function testTheGeneratedTimestampIsNotTreatedAsAChange(): void
    {
        $writer = new SnapshotWriter();

        $morning = $this->build('2026-09-15T02:00:00Z');
        $evening = $this->build('2026-09-16T02:00:00Z');

        self::assertNotSame($writer->encode($morning), $writer->encode($evening), 'The files do differ...');
        self::assertFalse($writer->ratesDiffer($morning, $evening), '...but the RATES do not.');
    }

    public function testARealRateChangeIsDetected(): void
    {
        $writer = new SnapshotWriter();

        $before = $this->build();
        $after = $before;
        $after['countries']['DK']['standard'][0]['percent'] = '26.00';

        self::assertTrue($writer->ratesDiffer($before, $after));
    }

    public function testANewTerritorialObservationIsAlsoAChange(): void
    {
        $writer = new SnapshotWriter();

        $before = $this->build();
        $after = $before;
        $after['territorial_observed']['PT'] = [['percent' => '16.00', 'class' => 'STANDARD', 'qualifier' => 'Somewhere New']];

        self::assertTrue($writer->ratesDiffer($before, $after), 'A territory appearing is news.');
    }
}
