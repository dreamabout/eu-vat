# TEDB fixtures

Real, unmodified responses from the European Commission's TEDB
`retrieveVatRates` service, captured 2026-09-15. They are committed verbatim so
the parser is tested against what the service actually returns, not against what
we believe it returns.

| File | Request | Why it is here |
|---|---|---|
| `tedb-dk-de-fi-2024-2026.xml` | `memberStates: DK, DE, FI`, `from: 2024-01-01`, `to: 2026-09-15` | Contains the **FI 24.0 -> 25.5 change on 2024-09-01** — the only real mid-semester rate change in the window, and the case interval derivation must get right |
| `tedb-eu27-current.xml` | all 27 member states (Greece as `EL`), `from: 2026-09-15`, `to: 2027-12-31` | Contains the **ES duplicate `STANDARD` rows** (21.0 mainland and 7.0 `comment: "VAT - Canary Islands"`) and the `AT REDUCED 19.0 "Jungholz, Mittelberg"` territorial row |

## Reproducing them

The request's child elements belong to the `...:types` namespace, not the message
namespace. Getting this wrong returns `TEDB-ERR-2 / The XSD validation failed`.

```bash
curl -X POST https://ec.europa.eu/taxation_customs/tedb/ws/VatRetrievalService \
  -H 'Content-Type: text/xml;charset=UTF-8' \
  -H 'SOAPAction: urn:ec.europa.eu:taxud:tedb:services:v1:VatRetrievalService/RetrieveVatRates' \
  --data-binary @request.xml
```

```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
  xmlns:m="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService"
  xmlns:t="urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types">
  <soapenv:Body>
    <m:retrieveVatRatesReqMsg>
      <t:memberStates><t:isoCode>DK</t:isoCode><t:isoCode>FI</t:isoCode></t:memberStates>
      <t:from>2024-01-01</t:from>
      <t:to>2026-09-15</t:to>
    </m:retrieveVatRatesReqMsg>
  </soapenv:Body>
</soapenv:Envelope>
```
