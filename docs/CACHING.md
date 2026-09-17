# Caching: was ausgeschlossen werden muss

Dieses Dokument listet exakt die URLs, Parameter, Dateien und Cache-Gruppen, die
ein Full-Page-Cache in Ruhe lassen muss, damit BlockSocial Filters korrekt
arbeitet. Der Schwerpunkt liegt auf **LiteSpeed Cache**, weil dessen Standard-
einstellungen als einzige den REST-Endpunkt des Plugins mitcachen.

---

## 1. Kurzfassung

Wenn du nur drei Dinge einträgst, dann diese:

| Wo | Was eintragen |
| --- | --- |
| **Cache → Excludes → Do Not Cache URIs** | `/wp-json/blocksocial-filters/` |
| **Page Optimization → Tuning → JS Deferred/Delayed Excludes** | `woo-blocksocial-filters/assets/js/frontend.js` |
| **Page Optimization → Tuning-CSS → CSS Excludes** | `woo-blocksocial-filters/assets/css/frontend.css` |

Danach einmal **Purge All** und den Index unter *Produktfilter → Index* neu
aufbauen.

---

## 2. Warum das nötig ist

Drei Eigenschaften des Plugins vertragen sich nicht mit den Voreinstellungen:

1. **Der Filter läuft über die REST-API.** `GET /wp-json/blocksocial-filters/v1/filter`
   liefert bei jedem Klick das neu gefilterte Produktraster **und die neu
   berechneten Facetten-Zähler**. LiteSpeed hat *Cache REST API* standardmäßig
   **an** — die Antwort wird also mitgecacht und nach einem Index-Neuaufbau
   weiterhin veraltet ausgeliefert. WP Rocket cached die REST-API grundsätzlich
   nicht; genau deshalb läuft dieselbe Version auf einem Rocket-Shop
   unauffällig und auf einem LiteSpeed-Shop nicht.

2. **Das Frontend-Script muss vor dem Theme laufen.** `frontend.js` hängt sich
   beim Laden in `window.fetch` und `XMLHttpRequest.prototype.open` ein, damit
   das Nachladen des Themes (OceanWP Infinite Scroll) die aktiven Filter
   mitschickt. Wird das Script verzögert (*Load JS Deferred* / *Delay JS*),
   hat das Theme seine erste Seite schon ohne Filter angefordert.

3. **Zustände entstehen erst im Browser.** Aufgeklappte Filter, ausgewählte
   Optionen, der mobile Drawer und die Farbfelder bekommen ihre Klassen erst
   per JavaScript. UCSS („Unique CSS") sieht diese Klassen beim Scannen der
   Seite nicht und wirft die zugehörigen Regeln weg.

---

## 3. LiteSpeed Cache — Felder und Werte

Die Feldnamen entsprechen der englischen Oberfläche von LiteSpeed Cache für
WordPress. Jede Zeile ist ein eigener Eintrag (ein Wert pro Zeile).

### Cache → Excludes → `Do Not Cache URIs`

```
/wp-json/blocksocial-filters/
```

Deckt alle drei Routen ab: `/filter`, `/index/status`, `/index/run`.

> Ohne hübsche Permalinks ruft WordPress die REST-API als
> `/?rest_route=/blocksocial-filters/v1/filter` auf. Dann zusätzlich unter
> **Cache → Excludes → `Do Not Cache Query Strings`** den Wert `rest_route`
> eintragen.

### Cache → Cache → `Cache REST API`

Auf **OFF** stellen, wenn die Facetten-Zähler nach einem Index-Neuaufbau nicht
sofort stimmen. Der URI-Ausschluss oben reicht normalerweise; dieser Schalter
ist die sichere Variante.

### Cache → Cache → `Drop Query String`

Hier dürfen die Parameter des Plugins **nicht** stehen. Prüfen, dass die Liste
keinen dieser Werte enthält:

```
f_
ordr
srch
```

Steht dort z. B. `f_`, wirft LiteSpeed den Filter aus der URL und liefert für
jede Filterkombination die ungefilterte Seite aus. Die LiteSpeed-Standardliste
(`fbclid`, `gclid`, `utm*`, `_ga`) ist unproblematisch.

### Cache → Excludes → `Do Not Cache Query Strings`

Gefilterte Seiten **sollen** gecacht werden — die Filterparameter gehören hier
also nicht hinein. Einzige Empfehlung:

```
srch
```

`srch` ist freie Nutzereingabe und erzeugt sonst beliebig viele Cache-Einträge.

### Page Optimization → Tuning → `JS Excludes`

```
woo-blocksocial-filters/assets/js/frontend.js
woo-blocksocial-filters/assets/js/swatches.js
```

### Page Optimization → Tuning → `JS Deferred/Delayed Excludes`

```
woo-blocksocial-filters/assets/js/frontend.js
woo-blocksocial-filters/assets/js/swatches.js
bsfData
```

`bsfData` ist das Inline-Script mit der Konfiguration; es muss vor
`frontend.js` stehen.

### Page Optimization → Tuning → `Guest Mode JS Excludes`

Nur nötig, wenn **Guest Mode** aktiv ist — dann dieselben drei Werte:

```
woo-blocksocial-filters/assets/js/frontend.js
woo-blocksocial-filters/assets/js/swatches.js
bsfData
```

### Page Optimization → Tuning-CSS → `CSS Excludes`

```
woo-blocksocial-filters/assets/css/frontend.css
woo-blocksocial-filters/assets/css/swatches.css
```

Das hält beide Dateien aus *CSS Combine* **und** aus der UCSS-Berechnung
heraus und ist der einfachste Weg.

### Page Optimization → Tuning-CSS → `UCSS Selector Allowlist`

Nur nötig, wenn du UCSS trotzdem auf die Plugin-CSS anwenden willst (statt des
Ausschlusses oben). Diese Klassen entstehen erst zur Laufzeit:

```
.bsf
.bsf--drawer
.bsf--inline-mobile
.bsf-drawer-toggle
.bsf-backdrop
.bsf-panel
.bsf-filter__body
.bsf-showmore
.bsf-loadmore
.bsf-chips
.bsf-swatches
.is-open
.is-selected
.is-collapsed
.is-expanded
.is-collapsible
.is-drawer-open
.is-hidden-extra
.is-busy
.is-loading
.is-disabled
.is-unavailable
.is-current
.is-on
.has-selection
```

### Object Cache → `Do Not Cache Groups`

**Nur als Notlösung.** Die Facetten-Zähler liegen in der Cache-Gruppe `bsf` und
werden über eine Versionsnummer (`bsf_cache_version`) verworfen. Liegt diese
Option in einem veralteten Redis/Memcached-Eintrag, werden alte Zähler
weiterbenutzt. Dann hilft:

```
bsf
```

Das schaltet den Zähler-Cache ab (langsamer, aber immer korrekt). Sauberer ist,
den Object Cache einmal komplett zu leeren.

### Nicht anfassen

- **ESI** wird nicht gebraucht.
- **Cache Logged-in Users** sollte **aus** bleiben. Das Plugin legt einen
  `wp_rest`-Nonce in die Seite; wird eine Seite für eingeloggte Nutzer länger
  als 12 Stunden gecacht, läuft der Nonce ab.
- **Lazy Load** braucht keinen Ausschluss.

---

## 4. „Es werden zu wenig Attribute angezeigt"

Das ist fast immer **kein** Darstellungsfehler, sondern ein Zähler von `0`:
Optionen, für die der Index keine Treffer kennt, werden ausgeblendet
(`hide_empty`). Der Reihe nach prüfen:

1. **Index vollständig?** *Produktfilter → Index*. Stimmt „Indexierte Produkte"
   mit „Produkte im Katalog" überein und ist die Warteschlange leer? Wenn
   nicht: Index neu aufbauen.
2. **Veraltete REST-Antwort?** Der häufigste Fall bei LiteSpeed. Eintrag aus
   Abschnitt 3 setzen (`/wp-json/blocksocial-filters/`), dann *Purge All*.
   Gegenprobe: die Filter-URL direkt im Browser aufrufen
   (`?f_farbe=schwarz`) — kommen dort mehr Attribute als beim Klick im
   AJAX-Betrieb, ist es der REST-Cache.
3. **Veralteter Object Cache?** Object Cache leeren. Bleibt es falsch, `bsf` in
   *Do Not Cache Groups* eintragen.
4. **Absichtlich begrenzt?** Im Filter-Set hat jeder Filter ein Feld
   **Limit** (Anzahl sichtbarer Optionen vor „Mehr anzeigen"). `0` = alle.
5. **Leere Optionen gewollt?** Wenn Optionen ohne Treffer sichtbar bleiben
   sollen, im Filter **„Leere ausblenden"** abschalten. Sie sind dann
   anklickbar, führen aber zu null Ergebnissen.

---

## 5. Andere Caches

**WP Rocket** — cached die REST-API nicht, deshalb meist unauffällig. Falls
nötig: *Erweiterte Regeln → Nie zwischenspeichern (URLs)* → `/wp-json/blocksocial-filters/(.*)`.
Unter *Dateioptimierung* die beiden JS-Dateien von „Verzögert ausführen"
ausnehmen. In *Query-Strings cachen* dürfen `f_*`, `ordr` und `srch` stehen,
damit gefilterte Seiten überhaupt gecacht werden.

**Cloudflare** — Filterparameter müssen Teil des Cache-Keys sein, sonst liefert
Cloudflare für jede Kombination dieselbe Seite. Eine Bypass-Regel für
`/wp-json/*` anlegen.

**Varnish / NGINX FastCGI** — `/wp-json/blocksocial-filters/` vom Cache
ausnehmen und die Query-Parameter nicht aus dem Cache-Key entfernen.

---

## 6. Referenz: alle Strings des Plugins

| Zweck | Wert | Anmerkung |
| --- | --- | --- |
| Filter-Parameter | `f_<schlüssel>` | Präfix einstellbar (*Einstellungen → URL-Präfix*) |
| Sortierung | `ordr` | fest |
| Stichwortsuche | `srch` | fest |
| Bereichstrenner | `..` | z. B. `f_preis=10..50` |
| Pretty-URLs | zusätzliche Pfadsegmente `schlüssel-wert` | nur im Modus „Pretty" |
| REST-Namespace | `blocksocial-filters/v1` | |
| REST: Filtern | `/wp-json/blocksocial-filters/v1/filter` | öffentlich, GET + POST |
| REST: Index-Status | `/wp-json/blocksocial-filters/v1/index/status` | nur Admin |
| REST: Index-Lauf | `/wp-json/blocksocial-filters/v1/index/run` | nur Admin |
| Script-Handles | `bsf-frontend`, `bsf-swatches` | |
| Inline-Konfiguration | `bsfData` | via `wp_localize_script` |
| Style-Handles | `bsf-frontend`, `bsf-swatches` | |
| CSS-Klassenpräfix | `bsf-` | plus Zustände `is-*`, `has-selection` |
| Object-Cache-Gruppe | `bsf` | |
| Versionsstempel | Option `bsf_cache_version` | |
| Datenbanktabellen | `bsf_index`, `bsf_product`, `bsf_numeric`, `bsf_queue` | mit `$wpdb->prefix` |
