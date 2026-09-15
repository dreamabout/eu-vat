# Verification status of the territory table

`data/rates.json` is synced from TEDB and needs no human verification — if it is wrong, the
Commission is wrong. `data/territories.json` is different: it encodes law and national postal
geography, neither of which can be synced, and it is only as good as the last person who checked
it.

This file records what has actually been verified, so the table's weakest data is visible rather
than implied.

## Verified

- **Which territories fall outside the EU VAT area**, and the article establishing each. Checked
  against the consolidated text of Council Directive 2006/112/EC — Article 6(1) (inside the
  customs territory, outside VAT), Article 6(2) (outside both), and Article 7 (Monaco, the Isle
  of Man, Akrotiri and Dhekelia treated as FR / GB / CY).

  This matters more than it sounds. The Commission's own "Territorial status of EU countries and
  certain territories" summary page, read programmatically, reports the Canary Islands, Mount
  Athos, the Åland Islands and the French overseas departments as being **inside** the VAT area.
  Article 6 says the opposite. The table follows the Directive.

- **Jungholz and Mittelberg at 19%** — corroborated independently by TEDB, which reports an
  Austrian 19% row commented `Jungholz, Mittelberg`.

## Not verified — needs a pass

- **Every postal code range.** These come from national postal authorities and national VAT law,
  not from any EU source. They are recorded with a `postal_source` naming where each came from,
  and `verified_on: null` because nobody has yet confirmed them against a primary source. They
  are believed correct and are in daily use elsewhere in the industry, but "believed correct" is
  not "checked".

  Verifying one means confirming the range against the national postal authority, then setting
  `verified_on` to the date you checked.

- **Madeira and the Azores rates.** Both are inside the VAT area at their own rates, lower than
  mainland Portugal's. The current values have not been confirmed from a Portuguese primary
  source, so both entries carry `rate_unverified: true` and **resolution throws** rather than
  returning the mainland rate. A plausible-looking wrong rate is worse than a refusal.

  To fix: confirm the current Madeira and Azores standard rates, add a `rate_override`, and
  remove `rate_unverified`.

## What keeps this honest

`bin/regenerate-rates` cross-checks every territorial rate TEDB reports against this table and
**fails** when TEDB names a territory the table does not contain. Automation cannot curate law,
but it can notice when the world moves.
