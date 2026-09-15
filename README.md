# kaikei/eu-vat

EU VAT rates with **point-in-time lookup**, territorial exceptions, and gross-first VAT
calculation.

Rates are synced from the European Commission's [Taxes in Europe Database
(TEDB)](https://ec.europa.eu/taxation_customs/tedb/) and shipped as a **committed snapshot**.
Consumers get a pure, offline, deterministic lookup — no database, no runtime dependency on
`ec.europa.eu`, no network in your test suite.

A nightly job re-syncs and commits the snapshot when a rate changes, so **the git diff is both
the change alert and the audit trail**.

## Install

```bash
composer require kaikei/eu-vat
```

## Use

```php
use Kaikei\EuVat\{VatRates, VatCalculator, Place};

$rates = VatRates::fromSnapshot();

// Point-in-time: Finland's standard rate rose 24% -> 25.5% on 2024-09-01.
$rates->standardRate('FI', new DateTimeImmutable('2024-08-31'))->percent;  // '24.00'
$rates->standardRate('FI', new DateTimeImmutable('2024-09-01'))->percent;  // '25.50'

// Territories change the answer, so a postcode is required where one exists.
$rates->rateFor(Place::of('DE', '78266'), $on);   // throws OutsideVatArea (Büsingen)
$rates->rateFor(Place::of('AT', '6691'),  $on);   // '19.00', not '20.00' (Jungholz)
$rates->rateFor(Place::of('MC', '98000'), $on);   // French rate (Directive Art. 7)
$rates->rateFor(Place::countryOnly('DE'), $on);   // throws PostalCodeRequired

// VAT is split OUT of a gross amount, matching how e-conomic momskoder book.
$calc = new VatCalculator();
$calc->vatFromGross('1250.00', $rate);            // '250.00'
$calc->netFromGross('1250.00', $rate);            // '1000.00'
```

Every rate and amount is a **decimal string**. Nothing in this package is ever a float.

## What this package does not do

- **It does not map products to rates.** TEDB publishes CN/CPA category lists per reduced rate;
  they are dropped from the snapshot. This package tells you *that Germany has a 7% reduced
  rate*, never *whether a given book qualifies for it*. That is a catalogue problem.
- **It does not cover `NO`, `CH` or post-Brexit `GB`.** They are not in TEDB. Consumers must
  treat them as out of scope explicitly rather than reading an absent rate as zero.
- **It does not warn you ahead of a rate change.** TEDB carries the latest known situation, not
  announced future changes, so detection is at or shortly after the effective date.

## Two data files, two different trust levels

| File | Source | Maintenance |
|---|---|---|
| `data/rates.json` | TEDB SOAP service | **Synced** nightly, fully automatic |
| `data/territories.json` | VAT Directive 2006/112/EC, cited per entry | **Curated** by hand, reviewed, changes only when the law does |

Territory data is never scraped. The Commission's own summary page, read programmatically,
reports the Canary Islands, Mount Athos, Åland and the French overseas departments as *inside*
the EU VAT area; Article 6 of the Directive says the opposite. Every entry therefore cites its
article, and postal codes — which are national data, not EU-published — carry a `postal_source`
and a `verified_on` date.

## Regenerating the snapshot

```bash
docker compose exec app bin/regenerate-rates              # sync and write
docker compose exec app bin/regenerate-rates --check      # exit non-zero if rates changed
docker compose exec app bin/regenerate-rates --fixture=…  # replay canned XML, no network
```

`--check` hashes `countries` + `territorial_observed` only, so the `generated_at` timestamp does
not produce an empty commit every night.

## How it stays current

A scheduled GitHub Action runs `--check` nightly. Exit codes are the contract:

| Code | Meaning | What the job does |
|---|---|---|
| 0 | No rate changed | Nothing. Silence is correct here |
| 1 | Rates changed | Regenerate, verify the snapshot loads, commit, tag a patch release, notify |
| 2 | **The run failed** | Fail loudly and notify |

Code 2 is separate from code 1 on purpose. A job that only speaks when rates change is
indistinguishable from a job that died six months ago — silence means the same thing in both
cases. So an unreachable TEDB, a SOAP fault, an unparseable response, or a territory nobody has
curated all announce themselves.

Set the `SLACK_WEBHOOK_URL` repository secret to receive those notifications. Without it the job
still runs and still fails correctly; it just has nowhere to shout.

## Development

All commands run inside the Docker `app` service — the same idiom as kaikei.

```bash
docker compose up -d --build
docker compose exec app composer install
docker compose exec app composer check     # cs-fixer + phpstan + phpunit
```
