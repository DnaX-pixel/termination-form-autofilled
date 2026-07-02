# GSR Document Generation System — Detailed Explanation

> Dokumentasi teknikal lengkap tentang cara kerja sistem penjanaan dokumen GSR (GEMS Service Request).
> Ditujukan untuk memahami arkitektur sedia ada dan sebagai rujukan untuk membina projek serupa yang lebih ringkas.

---

## 1. Konsep Asas — Kenapa Bukan PhpWord Template?

PhpWord ada `setValue('${name}', 'Ali')` tetapi ia **memusnahkan layout kompleks** — table positioning, DrawingML shapes, fonts. Sebabnya PhpWord parse OOXML jadi object model, kemudian serialize semula — banyak elemen tidak dipreserve (especially `mc:AlternateContent`, `wp:anchor`, custom shapes).

**GSR guna pendekatan berbeza**: edit `word/document.xml` secara terus dalam ZIP (.docx adalah ZIP archive). String replacement pada XML mentah — layout 100% kekal sebagaimana template asal.

---

## 2. Struktur .docx (OOXML)

```
gsr-template-v24.docx (sebenarnya ZIP)
├── word/
│   ├── document.xml          ← isi borang (text, tables, shapes)
│   ├── _rels/document.xml.rels  ← relationships (image rId → file)
│   └── media/
│       ├── image1.png ... image8.png  ← embedded images
├── [Content_Types].xml
└── docProps/
```

Setiap teks dalam Word dibungkus dalam struktur:

```xml
<w:p>                              <!-- paragraph -->
  <w:r>                            <!-- run (unit pemformatan) -->
    <w:rPr><w:b/><w:sz w:val="18"/></w:rPr>  <!-- bold, size 18 -->
    <w:t>S52121</w:t>              <!-- TEKS SEBENAR -->
  </w:r>
</w:p>
```

**Masalah utama**: Word sering split satu teks ke beberapa runs:

```xml
<w:t>${</w:t></w:r><w:r><w:t>staff_id</w:t></w:r><w:r><w:t>}</w:t>
```

Bukan `${staff_id}` satu string — jadi `str_replace` biasa tak jumpa.

---

## 3. Two-Phase Architecture

### Phase 1: Compile (sekali sahaja, di-cache)

**File**: `GsrDocxTemplateCompiler.php`

Tujuan: Tukar **sample text** dalam template jadi **`${placeholder}`** supaya export mudah ganti.

```
Template asal (ada sample data):
  <w:t>S52121</w:t>           →  <w:t>${staff_id}</w:t>
  <w:t>MOSLIE BIN MAJIMUN</w:t>  →  <w:t>${requestor_name}</w:t>
  <w:t>23/02/2026</w:t>       →  <w:t>${request_date}</w:t>
```

Cache di `storage/framework/cache/gsr-template-compiled-{md5}-v31.docx`. Bergantung pada hash fail source — kalau template tak berubah, compile skip.

### Phase 2: Export (setiap request)

**File**: `GsrDocxExportService.php`

Ambil compiled template → ganti `${placeholder}` dengan data DB → inject signature images → convert ke PDF.

```
Compiled template + DB data → str_replace → filled .docx → LibreOffice → PDF
```

---

## 4. Kaedah Placeholder Injection — Detail Teknikal

### Kaedah A: Direct String Replace (paling simple)

Untuk teks yang ada dalam satu `<w:t>` run:

```php
// Dalam compiler:
$xml = str_replace('<w:t>S52121</w:t>', '<w:t>${staff_id}</w:t>', $xml);
$xml = str_replace('<w:t>011-26850938</w:t>', '<w:t>${rb_contact}</w:t>', $xml);

// Dalam export:
$xml = str_replace('${staff_id}', $escapedValue, $xml);
```

**Syarat**: sample text mesti unique dalam dokumen. `S52121` unique, jadi selamat.

### Kaedah B: Regex Pattern Replace (untuk paragraph kompleks)

Description field — sample text panjang dalam satu paragraph:

```php
$descPattern = '/<w:p[^>]*>(?:(?!<\/w:p>).)*<w:t>PO: 4902292567\. To clear...<\/w:t>(?:(?!<\/w:p>).)*<\/w:p>/s';
$xml = preg_replace_callback($descPattern, function ($m) {
    return '<w:p>...<w:t>${description}</w:t></w:p>';
}, $xml);
```

Replace **seluruh `<w:p>` block** supaya formatting paragraph kekal.

### Kaedah C: Split-Run Merge (Word split teks ke beberapa runs)

Bila Word split `${pic_contact}` jadi:

```xml
<w:t>013-</w:t>...<w:t>8562312</w:t>
```

```php
private function mergeAdjacentWtRunsToPlaceholder($xml, $part1, $part2, $name) {
    $pattern = '/<w:t[^>]*>'.$part1.'<\/w:t>([\s\S]*?)<w:t[^>]*>'.$part2.'<\/w:t>/u';
    return preg_replace_callback($pattern, function () use ($name) {
        return '<w:t>${'.$name.'}</w:t>';
    }, $xml, 1);
}
```

Cari dua `<w:t>` berdekatan → ganti jadi satu placeholder.

### Kaedah D: Split Macro Reassembly (bila `${var}` sendiri di-split)

Word kadang-kadang break `${staff_id}`:

```xml
<w:t>${</w:t></w:r>...<w:r><w:t>staff_id</w:t></w:r>...<w:r><w:t>}</w:t>
```

```php
private function replaceSplitMacro($xml, $name, $value) {
    $pattern = '/<w:t>\$\{<\/w:t><\/w:r>[\s\S]*?<w:t>'.$name.'<\/w:t>[\s\S]*?<w:t>\}<\/w:t>/u';
    return preg_replace_callback($pattern, function ($m) use ($value) {
        $rPr = '';
        if (preg_match('/<w:rPr>[\s\S]*?<\/w:rPr>/', $m[0], $rp)) $rPr = $rp[0];
        return '<w:r>'.$rPr.'<w:t>'.$value.'</w:t></w:r>';
    }, $xml);
}
```

### Kaedah E: DOMXPath (untuk cari berdasarkan struktur, bukan teks)

Bila kita tak tahu teks sebenar, guna query XML:

```php
// Cari baris "Approved by (GM/CFO)" walaupun teks split
$queries = [
    '//w:tr[contains(., "Approved by") and contains(., "GM/CFO") and not(contains(., "Supported by"))]',
    '//w:tr[.//w:t[contains(., "Approved by")] and .//w:t[contains(., "GM/CFO")]]',
];
```

`contains(., ...)` match pada **string value seluruh node** (gabungan semua `<w:t>` dalam row) — bukan text satu-satu run.

---

## 5. Precise Location — Boxed Fields (Paling Complex)

### Request Date — 10 digit boxes (DD/MM/YYYY)

Borang GSR ada **kotak lukisan** (DrawingML shapes) untuk setiap digit. Tiada slot teks dalam kotak — ia drawing, bukan table cell.

**Masalah**: `${rq1}…${rq10}` sebagai teks inline akan "cram together" — bukan duduk dalam kotak.

**Penyelesaian**: Overlay transparent text shapes **di atas setiap kotak** pada koordinat tepat:

```php
private function injectRequestDateBoxOverlays($xml) {
    // 1. Cari 3 shapes (DD, MM, YYYY) berdasarkan vertical offset (EMU)
    // V = 33032 EMU = posisi Y shape pada page
    preg_match_all('/<mc:Choice Requires="wps">.*?<\/mc:Choice>/su', $xml, $mm);

    // 2. Bagi setiap shape, dapatkan horizontal offset + extent (lebar)
    // h = positionH posOffset, cx = extent cx

    // 3. Kira center setiap cell:
    $cells = function($shape, $n) {
        for ($i = 0; $i < $n; $i++) {
            $out[] = $shape['h'] + round($shape['cx'] * (2*$i + 1) / (2*$n));
        }
        return $out;
    };
    $c1 = $cells($s1, 2);  // rq1, rq2 (DD - 2 cells)
    $g1 = intdiv($s1['h'] + $s1['cx'] + $s2['h'], 2);  // rq3 "/"
    $c2 = $cells($s2, 2);  // rq4, rq5 (MM)
    // ... sehingga rq10

    // 4. Generate DrawingML shape transparent dengan ${rqN} di setiap center
    foreach ($plan as [$ph, $hh]) {
        $runs .= $this->requestDateOverlayRun($hh, $vOffset, $ph, $i);
    }
}
```

Shape yang dijana:

```xml
<w:r><w:drawing>
  <wp:anchor allowOverlap="1" layoutInCell="1">
    <wp:positionH relativeFrom="page"><wp:posOffset>1234567</wp:posOffset></wp:positionH>
    <wp:positionV relativeFrom="paragraph"><wp:posOffset>33032</wp:posOffset></wp:positionV>
    <wp:extent cx="152400" cy="180000"/>
    <wp:wrapNone/>           <!-- tidak ganggu layout lain -->
    <a:graphic>...<w:txbxContent>
      <w:p><w:r><w:t>${rq1}</w:t></w:r></w:p>
    </w:txbxContent></a:graphic>
  </wp:anchor>
</w:drawing></w:r>
```

**Koordinat dalam EMU (English Metric Unit)**: 1 inch = 914400 EMU. Borang GSR pegun — koordinat di-hardcode berdasarkan template sebenar.

### Section B Checkboxes — Tick marks

Sama konsep: box checkboxes adalah DrawingML shapes tanpa slot teks. Overlay `${mfin}`, `${ctm}` di koordinat setiap box:

```php
$rows = [
    905696  => ['mfin','mscm','msrm','meam','mhcm','mess','mbw','mrarbcs','mothers'],
    1105137 => ['ctm','ctmtech','ctmrd','cgitn','ctmf','ctmfa','cmmu','cvads','cvadsbpo'],
    1315372 => ['cytm','ctmro','cfiberail','ctmdi','cothers'],
];
$tol = 16500; // tolerance ~1.3pt

// Match setiap shape ke placeholder berdasarkan vertical offset (row)
// + horizontal offset (column position within row)
foreach ($boxes as $b) {
    foreach ($rows as $center => $keys) {
        if (abs($b['v'] - $center) <= $tol) {
            $b['row'] = $center;
            break;
        }
    }
}
// Sort left-to-right by horizontal offset, assign keys[$i]
```

Export ganti `${mfin}` → `✓` (jika dipilih) atau kosong.

---

## 6. Image Injection — Signatures & Stamps

### Konsep

Template ada placeholder PNG images (`image4.png` = RB signature, `image7.png` = PIC, `image8.png` = GM). Export **overwrite bytes PNG tersebut** dalam ZIP dengan fail upload user.

```php
private function injectPngIntoZip(ZipArchive $zip, $zipPath, $storedPath) {
    $absolute = Storage::disk('local')->path($storedPath);
    $png = $this->imageFileToPngBytes($absolute);  // convert jpg→png kalau perlu
    $zip->deleteName($zipPath);           // buang image lama
    $zip->addFromString($zipPath, $png);  // letak image baru
}
```

**Maksudnya**: dokumen Word tetap rujuk `image4.png` — tapi bytes dalam ZIP dah ditukar. Word/LibreOffice render image baru pada position yang sama.

### Blank Signature (slot kosong)

Bila PIC belum approve, slot signature mesti kosong (putih). Ganti dengan **1×1 white PNG**:

```php
private function blankSignaturePlaceholderPng() {
    // 1×1 pixel putih opaque (GD) atau base64 fallback
    $im = imagecreatetruecolor(1, 1);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefill($im, 0, 0, $white);
    ob_start(); imagepng($im); return ob_get_clean();
}
```

> **Kenapa putih bukan transparent?** PNG transparent render sebagai **magenta/pink** dalam Word — bug lama Microsoft. Putih match background borang.

### Rerouting Image (elak konflik)

Template v24: PIC particulars stamp dan GM signature berkongsi `image7.png`. Kalau inject PIC stamp, GM signature juga bertukar.

**Penyelesaian**: reroute PIC particulars ke `image10.png` (fail baharu):

```php
// 1. Cari "Supported by" row
// 2. Cari blip kedua dalam row (particulars, bukan signature)
// 3. Tukar r:embed="rId7" → r:embed="rIdNew"
// 4. Append relationship baru ke rels file: Target="media/image10.png"
// 5. Copy image7.png → image10.png dalam ZIP (baseline)
```

---

## 7. PDF Conversion

```php
// DOCX → PDF
private function createPdfForRequest($request) {
    $docxPath = $this->generateFilledDocx($request);
    $pdfPath = ...;

    if (engine === 'libreoffice') {
        $this->convertWithLibreOffice($docxPath, $pdfPath);
    } elseif (engine === 'word') {
        // Word COM (Windows only) - lebih accurate
        $word = new \COM('Word.Application');
        $document = $word->Documents->Open($docxPath);
        $document->ExportAsFixedFormat($pdfPath, 17); // 17 = PDF
    }
}
```

LibreOffice headless:

```
soffice --headless --convert-to pdf:writer_pdf_Export --outdir /tmp file.docx
```

> **Kenapa LibreOffice bukan DOMPDF?** DOMPDF render HTML/CSS → PDF. Borang GSR ialah .docx dengan layout Word kompleks (tables, absolute positioning, DrawingML). Convert melalui LibreOffice/Word preserve layout 100% sebab ia render OOXML native.

---

## 8. Validasi XML Sebelum Tulis

```php
private function assertWordDocumentXmlIsWellFormed($xml) {
    $dom = new \DOMDocument;
    $ok = @$dom->loadXML($xml, LIBXML_NONET);
    if ($ok !== true) {
        throw new RuntimeException('Generated word/document.xml is not well-formed: ...');
    }
}
```

Word **refuse open .docx kalau XML tak well-formed** — error cryptic dari COM. Validate dulu supaya error message jelas.

---

## 9. Yang Perlu Dibuat untuk Project Simple (Generate-Only)

Struktur minimum:

```
my-project/
├── app/
│   ├── Models/Document.php          # record + fields
│   ├── Services/
│   │   ├── TemplateCompiler.php     # compile template → cached .docx dengan ${placeholders}
│   │   └── DocxExporter.php         # fill placeholders + convert PDF
│   └── Http/Controllers/DocumentController.php  # form + store + download
├── resources/templates/
│   └── my-template.docx             # template dengan sample text
├── config/templates.php             # config sample text, image paths
```

### Yang BOLEH DROPPED (untuk simple version):

- ❌ Auth, roles, middleware (Breeze)
- ❌ Workflow state machine, approval flow
- ❌ PIC/GM controllers, workflow events table
- ❌ Signature conditional exposure (`shouldExposePicSignatureInExport`)
- ❌ Revision notes, drop/revision logic
- ❌ Admin user management

### Yang WAJIB KEEP:

- ✅ **TemplateCompiler** — inject `${placeholder}` ke template
- ✅ **DocxExporter** — `str_replace` + image injection + PDF convert
- ✅ **Split-run merge** — handle Word split teks
- ✅ **DOMXPath fallback** — cari row/cell bila teks di-split
- ✅ **Blank PNG** — untuk slot image kosong
- ✅ **LibreOffice conversion** — .docx → PDF
- ✅ **XML validation** — sebelum tulis .docx
- ✅ **Config** — sample text markers, image zip paths
- ✅ **Raw Data table + Lookup API** — auto-fill berdasarkan unique ID

### Aliran Simple (Manual Key-In):

```
1. User isi form (nama, staff_id, tarikh, dll)
2. Save ke DB
3. Click "Generate" →
   a. Load compiled template (cached)
   b. str_replace ${nama} → "Ali", ${staff_id} → "S123", dll
   c. Kalau ada signature upload → inject PNG ke ZIP
   d. LibreOffice convert → PDF
4. Download .docx atau .pdf
```

### Aliran dengan Raw Data Auto-Fill (Unique ID Lookup):

```
1. Sistem ada table "raw_data" (import dari CSV/Excel/API/sistem lain)
   - unique_id (cth: staff_id, no pekerja, no lesen)
   - nama, jawatan, jabatan, email, telefon, dll
2. User hanya masukkan Unique ID dalam form
3. AJAX request → backend query raw_data → return JSON
4. Form auto-fill baki field (nama, jabatan, telefon) secara real-time
5. User review & submit (boleh edit kalau perlu)
6. Save ke DB + generate dokumen (sama seperti aliran manual)
```

#### Arkitektur Auto-Fill

```
[Browser]                        [Laravel Server]
    |                                   |
    |  User type staff_id "S123"        |
    |  (blur / debounce event)          |
    |                                   |
    |  GET /api/lookup?unique_id=S123   |
    |---------------------------------->|
    |                                   |  Query raw_data table
    |                                   |  SELECT * FROM raw_data
    |                                   |  WHERE unique_id = 'S123'
    |                                   |
    |  200 OK { json }                  |
    |<----------------------------------|
    |                                   |
    |  JavaScript auto-fill:            |
    |  nama.value = data.nama           |
    |  jabatan.value = data.jabatan     |
    |  telefon.value = data.telefon     |
    |                                   |
    |  User review, submit form         |
    |---------------------------------->|
    |                                   |  Save + Generate .docx/.pdf
    |  Download                         |
    |<----------------------------------|
```

#### Database — Table Raw Data

```sql
CREATE TABLE raw_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unique_id VARCHAR(255) NOT NULL UNIQUE,  -- staff_id / no pekerja / no lesen
    nama VARCHAR(255),
    jawatan VARCHAR(255),
    jabatan VARCHAR(255),
    email VARCHAR(255),
    telefon VARCHAR(50),
    -- tambah field ikut keperluan
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE INDEX raw_data_unique_id_idx ON raw_data(unique_id);
```

#### Model

```php
// app/Models/RawData.php
class RawData extends Model {
    protected $table = 'raw_data';
    protected $fillable = ['unique_id', 'nama', 'jabatan', 'email', 'telefon'];
}
```

#### Route + Controller (Lookup API)

```php
// routes/web.php
Route::get('/api/lookup', [LookupController::class, 'lookup']);

// app/Http/Controllers/LookupController.php
class LookupController extends Controller {
    public function lookup(Request $request) {
        $request->validate(['unique_id' => 'required|string|max:255']);
        $record = RawData::where('unique_id', $request->unique_id)->first();

        if (!$record) {
            return response()->json([
                'found' => false,
                'message' => 'ID tidak dijumpai dalam raw data.',
            ], 404);
        }

        return response()->json([
            'found'   => true,
            'nama'    => $record->nama,
            'jabatan' => $record->jabatan,
            'email'   => $record->email,
            'telefon' => $record->telefon,
        ]);
    }
}
```

#### Blade Form dengan Auto-Fill JavaScript

```html
<form action="{{ route('documents.store') }}" method="POST">
    @csrf

    <!-- User hanya isi ini -->
    <label>Unique ID (Staff ID)</label>
    <input type="text" id="unique_id" name="unique_id"
           placeholder="Cth: S123" required
           onblur="autoFill()">

    <!-- Auto-filled (readonly atau editable) -->
    <label>Nama</label>
    <input type="text" id="nama" name="nama" readonly>

    <label>Jawatan</label>
    <input type="text" id="jawatan" name="jawatan" readonly>

    <label>Jabatan</label>
    <input type="text" id="jabatan" name="jabatan" readonly>

    <label>Telefon</label>
    <input type="text" id="telefon" name="telefon" readonly>

    <!-- Field manual lain -->
    <label>Tarikh</label>
    <input type="text" name="tarikh" placeholder="DD/MM/YYYY">

    <button type="submit">Generate Dokumen</button>
</form>

<div id="lookup-status"></div>

<script>
async function autoFill() {
    const uniqueId = document.getElementById('unique_id').value.trim();
    const status = document.getElementById('lookup-status');

    if (!uniqueId) return;

    status.textContent = 'Mencari...';
    status.className = 'text-blue-600';

    try {
        const res = await fetch(`/api/lookup?unique_id=${encodeURIComponent(uniqueId)}`);
        const data = await res.json();

        if (data.found) {
            document.getElementById('nama').value    = data.nama || '';
            document.getElementById('jawatan').value = data.jawatan || '';
            document.getElementById('jabatan').value = data.jabatan || '';
            document.getElementById('telefon').value = data.telefon || '';
            status.textContent = 'Data dijumpai & diisi auto.';
            status.className = 'text-green-600';
        } else {
            status.textContent = data.message || 'ID tidak dijumpai.';
            status.className = 'text-red-600';
        }
    } catch (e) {
        status.textContent = 'Ralat sambungan. Sila isi manual.';
        status.className = 'text-red-600';
    }
}
</script>
```

#### Import Raw Data (Seeder / Command)

```php
// app/Console/Commands/ImportRawData.php
class ImportRawData extends Command {
    protected $signature = 'data:import {file}';
    protected $description = 'Import raw data dari CSV ke database';

    public function handle() {
        $file = $this->argument('file');
        $rows = array_map('str_getcsv', file($file));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            $data = array_combine($header, $row);
            RawData::updateOrCreate(
                ['unique_id' => $data['unique_id']],
                [
                    'nama'    => $data['nama'] ?? null,
                    'jabatan' => $data['jabatan'] ?? null,
                    'email'   => $data['email'] ?? null,
                    'telefon' => $data['telefon'] ?? null,
                ]
            );
        }

        $this->info('Import selesai: ' . count($rows) . ' rekod.');
    }
}
```

```bash
php artisan data:import storage/app/raw_staff.csv
```

#### Contoh CSV

```csv
unique_id,nama,jabatan,email,telefon
S123,Ahmad bin Ali,Finance,ahmad@tm.com,0123456789
S456,Siti binti Hassan,HR,siti@tm.com,0198765432
```

#### Keselamatan & Validasi

- **Rate limiting** elak abuse lookup API:
  ```php
  Route::middleware('throttle:60,1')->get('/api/lookup', [LookupController::class, 'lookup']);
  ```
- **Sanitize input** — `unique_id` valid sebelum query DB.
- **Fallback manual** — kalau ID tak jumpa, user masih boleh isi field manual (jadikan field auto-fill editable, bukan readonly).
- **Cache query** kalau raw data besar:
  ```php
  $record = Cache::remember("lookup:{$uniqueId}", 300, fn() =>
      RawData::where('unique_id', $uniqueId)->first()
  );
  ```

### Tips Buat Template Baru

1. **Mulakan dengan .docx sebenar** (lukis dalam Word) — letak **sample text unik** di setiap field:
   - `NAMA_SAMPLE_HERE` untuk nama
   - `STAFFID123` untuk staff ID
   - `31/12/2026` untuk tarikh

2. **Pastikan setiap sample text unik** dalam dokumen — sebab `str_replace` replace semua occurrences.

3. **Test dengan PhpWord atau buka .docx sebagai ZIP** untuk lihat `word/document.xml` — semak sama ada sample text ada dalam satu `<w:t>` atau split ke beberapa runs.

4. **Kalau satu `<w:t>`** → guna Kaedah A (str_replace).
   **Kalau split** → guna Kaedah C (merge runs) atau Kaedah D (split macro).

5. **Untuk images** — letak image placeholder dalam template (cth. logo), buka `word/media/` dalam ZIP, catat nama fail (`image1.png`). Export overwrite fail tersebut.

6. **Kalau perlu precise positioning** (kotak, checkbox) — guna Kaedah E (overlay shapes dengan koordinat EMU). Tapi kalau project simple, elakkan — guna table cells biasa yang ada `<w:t>` slot.

### Code Skeleton untuk Simple Version

**TemplateCompiler.php** (versi simple):

```php
class TemplateCompiler {
    public function compile(string $sourceDocx): string {
        $zip = new ZipArchive();
        $zip->open($sourceDocx);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        // Ganti sample text → ${placeholder}
        $replacements = config('templates.replacements');
        // ['NAMA_SAMPLE_HERE' => '${nama}', 'STAFFID123' => '${staff_id}', ...]
        foreach ($replacements as $sample => $placeholder) {
            $xml = str_replace('<w:t>'.$sample.'</w:t>', '<w:t>'.$placeholder.'</w:t>', $xml);
        }

        // Tulis balik ke cached .docx
        $cached = storage_path('framework/cache/template-compiled.docx');
        copy($sourceDocx, $cached);
        $zip = new ZipArchive();
        $zip->open($cached);
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $cached;
    }
}
```

**DocxExporter.php** (versi simple):

```php
class DocxExporter {
    public function generate(Document $doc): string {
        $template = $this->compiler->compile(config('templates.source_path'));
        $tempPath = tempnam(sys_get_temp_dir(), 'doc_').'.docx';
        copy($template, $tempPath);

        $zip = new ZipArchive();
        $zip->open($tempPath);
        $xml = $zip->getFromName('word/document.xml');

        // Replace placeholders
        $fields = [
            'nama' => htmlspecialchars($doc->nama),
            'staff_id' => htmlspecialchars($doc->staff_id),
            'tarikh' => htmlspecialchars($doc->tarikh),
        ];
        foreach ($fields as $key => $value) {
            $xml = str_replace('${'.$key.'}', $value, $xml);
        }

        // Validate
        $dom = new \DOMDocument();
        @$dom->loadXML($xml);  // throw kalau invalid

        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $tempPath;
    }

    public function toPdf(string $docxPath): string {
        $pdfPath = str_replace('.docx', '.pdf', $docxPath);
        exec("soffice --headless --convert-to pdf --outdir ".dirname($pdfPath)." $docxPath");
        return $pdfPath;
    }
}
```

---

## 10. Ringkasan Kaedah

| Kaedah | Bila guna | Kompleksiti |
|--------|----------|------------|
| **A. str_replace** | Sample text dalam satu `<w:t>`, unique | Rendah |
| **B. preg_replace_callback** | Sample text dalam paragraph kompleks | Sederhana |
| **C. Merge split runs** | Word split sample ke 2 `<w:t>` berdekatan | Sederhana |
| **D. Split macro reassembly** | `${var}` sendiri di-split Word | Tinggi |
| **E. DOMXPath query** | Cari berdasarkan struktur (row, cell) bukan teks | Tinggi |
| **F. EMU coordinate overlay** | DrawingML shapes tanpa slot teks (kotak, checkbox) | Sangat tinggi |
| **G. ZIP image overwrite** | Tukar signature/logo dalam template | Rendah |

Untuk project simple, **Kaedah A + G cukup** kalau template anda:
- Gunakan table cells biasa (bukan drawing shapes)
- Setiap sample text dalam satu `<w:t>` run
- Tak ada checkbox lukisan

Kalau template ada kotak tarikh atau checkbox, baru perlu Kaedah F. Kalau Word split teks, baru perlu Kaedah C/D. Mula dengan simple — kalau ada masalah, tingkatkan kaedah satu-satu.

---

## 11. Rujukan Fail dalam Projek GSR

| Fail | Tujuan |
|------|-------|
| `app/Services/GsrDocxTemplateCompiler.php` | Compile template + inject placeholders (Phase 1) |
| `app/Services/GsrDocxExportService.php` | Export .docx + PDF conversion (Phase 2) |
| `app/Services/GsrSectionBDocxInjector.php` | Reference snippet untuk Section B checkboxes |
| `config/gsr.php` | Config template path, sample markers, image zip paths, PDF engine |
| `config/gsr_section_b.php` | Senarai modules & companies untuk Section B |
| `resources/gsr/gsr-template-v24.docx` | Template Word rasmi |
| `docs/word-engine-setup.md` | Setup Microsoft Word COM engine (Windows) |

---

## 12. Aliran Lengkap dengan Auto-Fill (End-to-End)

```
┌─────────────────────────────────────────────────────────────────┐
│  1. SETUP (sekali sahaja)                                       │
│                                                                 │
│  Import raw_data.csv ──► MySQL raw_data table                   │
│  Compile template.docx ──► cached compiled .docx (with ${...})  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  2. USER ISI FORM                                               │
│                                                                 │
│  User masukkan Unique ID "S123"                                 │
│       │ (onblur event)                                          │
│       ▼                                                         │
│  AJAX: GET /api/lookup?unique_id=S123                           │
│       │                                                         │
│       ▼                                                         │
│  Server: SELECT * FROM raw_data WHERE unique_id='S123'          │
│       │                                                         │
│       ▼                                                         │
│  JSON response: { nama, jawatan, jabatan, telefon }             │
│       │                                                         │
│       ▼                                                         │
│  JavaScript auto-fill form fields                               │
│  User review → submit                                           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  3. SAVE & GENERATE                                             │
│                                                                 │
│  Controller store() →                                           │
│    a. Validate input                                            │
│    b. Save ke table documents                                   │
│    c. Load compiled template (cached .docx)                     │
│    d. str_replace ${nama} → "Ahmad bin Ali", dll                │
│    e. Kalau ada signature upload → inject PNG ke ZIP            │
│    f. Validate XML well-formed                                  │
│    g. Save .docx ke temp                                        │
│    h. LibreOffice convert .docx → .pdf                          │
│                                                                 │
│  Redirect ke preview page                                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  4. DOWNLOAD / PREVIEW                                          │
│                                                                 │
│  Preview page: iframe PDF (inline)                              │
│  Download buttons: .docx | .pdf                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Senarai Fail untuk Projek Simple + Auto-Fill

```
my-project/
├── app/
│   ├── Models/
│   │   ├── Document.php           # rekod borang yang diisi
│   │   └── RawData.php            # table rujukan auto-fill
│   ├── Services/
│   │   ├── TemplateCompiler.php   # compile template → cached .docx
│   │   └── DocxExporter.php       # fill placeholders + convert PDF
│   └── Http/Controllers/
│       ├── DocumentController.php # CRUD + generate + download
│       └── LookupController.php   # AJAX auto-fill API
├── database/migrations/
│   ├── create_documents_table.php
│   └── create_raw_data_table.php
├── resources/views/
│   ├── documents/
│   │   ├── create.blade.php       # form dengan auto-fill JS
│   │   ├── show.blade.php         # preview + download
│   │   └── index.blade.php        # senarai
│   └── layouts/app.blade.php
├── resources/templates/
│   └── my-template.docx           # template Word dengan sample text
├── routes/web.php
├── config/templates.php           # config sample text, image paths
└── app/Console/Commands/
    └── ImportRawData.php          # import CSV ke raw_data table
```