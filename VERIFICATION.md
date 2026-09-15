# Verification status of the territory table

`data/rates.json` is synced from TEDB and needs no human verification — if it is wrong, the
Commission is wrong. `data/territories.json` is different: it encodes law and national postal
geography, neither of which can be synced, and it is only as good as the last person who checked
it.

This file records what has actually been verified, so the table's weakest data is visible rather
than implied.

## Verified against primary law

**Which territories fall outside the EU VAT area**, and the article establishing each. Checked
against the consolidated text of Council Directive 2006/112/EC — Article 6(1) (inside the customs
territory, outside VAT), Article 6(2) (outside both), and Article 7 (Monaco, the Isle of Man,
Akrotiri and Dhekelia treated as FR / GB / CY). Overseas countries and territories are outside
the EU altogether under TFEU Art. 355(2) and Annex II.

This matters more than it sounds. The Commission's own "Territorial status of EU countries and
certain territories" summary page, read programmatically, reports the Canary Islands, Mount Athos,
the Åland Islands and the French overseas departments as being **inside** the VAT area. Article 6
says the opposite. The table follows the Directive.

## Verified postal rules

Every entry with `postal_coverage: "complete"` carries a `postal_source` naming the national
authority it came from and a `verified_on` date. Checked 2026-09-15:

| Territory | Rule | Source |
|---|---|---|
| Büsingen | 78266 | Deutsche Post; UStG §1(2) |
| Heligoland | 27498 | Deutsche Post |
| Canary Islands | 35, 38 | Correos — province prefixes (Las Palmas, Santa Cruz de Tenerife) |
| Ceuta / Melilla | 51 / 52 | Correos — the two autonomous cities' province codes |
| French overseas departments | 971–976 | La Poste — three-digit overseas prefixes |
| Saint-Martin / Saint-Barthélemy | 97150 / 97133 exact | La Poste — kept their 971xx codes after the 2007 split |
| French OCTs | 975, 984, 986, 987, 988 | La Poste |
| Corsica | 20 | La Poste — both departments kept the pre-1976 number |
| Åland | 22, and the AX- prefixed spelling | Posti / UPU addressing guide |
| Mount Athos | 63086 | ELTA — Karyes, the postal address of every monastery |
| Campione d'Italia | 22061 | Poste Italiane |
| Livigno | 23041, and legacy 23030 | Poste Italiane |
| Jungholz / Mittelberg | 6691, 6991–6993 | Österreichische Post |
| Madeira / Azores | 90–93 / 95–99 | CTT |
| Monaco | 98000 | single code for the principality |
| Isle of Man / Northern Ireland | IM / BT | Royal Mail postcode areas |

**Rates for territories that differ from their member state** were cross-checked against two
independent sources: Madeira 22%, Azores 16% (PwC Worldwide Tax Summaries and Portuguese VAT
guides agree on the standard rate). Jungholz and Mittelberg at 19% are corroborated by TEDB
itself, which reports an Austrian 19% row marked `category = REGION`.

## Known gaps, declared rather than hidden

Three entries carry `postal_coverage: "none"` — they exist in the table, but no postcode will
match them:

- **The Aegean reduced-rate islands (EL).** Greek law cuts VAT by 30% on qualifying Aegean
  islands (24% → 17%), extended from 2026-01-01 to the North Aegean, Samothrace and the
  Dodecanese for populations up to 20,000. **Qualification is by island and population, not by
  postcode**, and roughly 24 islands qualify across non-contiguous Greek postcode ranges. Mapping
  them would need per-island verification nobody has done, and a wrong mapping charges 17% where
  24% is due. Greek addresses therefore resolve at the mainland 24%: right for the large
  majority, wrong for these islands. To close it, verify the postcodes per island, add a
  `rate_override` of 17.00, and clear `rate_unverified`.

- **The Italian waters of Lake Lugano.** A body of water. Not addressable.

- **Akrotiri and Dhekelia.** The Sovereign Base Areas use Cypriot addressing, so no postcode
  could distinguish them — and they are treated as Cyprus anyway, so the answer is the same.

Two further limitations worth knowing:

- **Jungholz and Mittelberg also carry German postcodes** (D-87491, D-87567/87568/87569) because
  German post serves them. An order addressed with country `DE` resolves as German — the same
  19%, but attributed to the wrong country for OSS. Only the Austrian spellings are matched;
  claiming a German postcode is Austrian would be worse.

- **Only standard-rate overrides are modelled.** Madeira's 12% intermediate and 4% super-reduced,
  the Azores' 9% and 4%, and Corsica's 0.9/2.1/10/13 schedule are documented in each entry's
  notes but are not returned by the lookup.

## What keeps this honest

`bin/regenerate-rates` cross-checks every regional rate TEDB reports against this table and
**fails** when TEDB names a region the table does not contain. That is not theoretical: it is how
Corsica, the Aegean islands and the Azores/Madeira regional rates were discovered missing in the
first place. Automation cannot curate law, but it can notice when the world moves.
