<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Termination Form Generator</title>
<style>
  :root {
    --ink: #1b1f24;
    --muted: #667085;
    --line: #d9dee3;
    --accent: #0b5fff;
    --accent-dark: #0847c4;
    --bg: #f5f6f8;
    --ok: #067d47;
    --err: #c8281f;
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg);
    color: var(--ink);
    margin: 0;
    padding: 40px 20px;
  }
  .card {
    max-width: 640px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 32px 36px 36px;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06);
  }
  h1 {
    font-size: 20px;
    margin: 0 0 4px;
  }
  p.sub {
    color: var(--muted);
    font-size: 13.5px;
    margin: 0 0 28px;
  }
  label {
    display: block;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 6px;
  }
  .field { margin-bottom: 18px; }
  input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    font-size: 14.5px;
    border: 1px solid var(--line);
    border-radius: 6px;
    background: #fff;
    color: var(--ink);
  }
  input[type="text"]:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(11, 95, 255, 0.12);
  }
  input[readonly] {
    background: #fafbfc;
    color: #40474f;
  }
  #service_number { font-weight: 600; letter-spacing: 0.02em; }
  .status {
    font-size: 13px;
    margin: -8px 0 20px;
    min-height: 18px;
  }
  .status.ok { color: var(--ok); }
  .status.err { color: var(--err); }
  .status.pending { color: var(--muted); }
  .actions {
    display: flex;
    gap: 10px;
    margin-top: 28px;
    border-top: 1px solid var(--line);
    padding-top: 24px;
  }
  button {
    flex: 1;
    padding: 11px 16px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 7px;
    border: 1px solid transparent;
    cursor: pointer;
  }
  button.primary {
    background: var(--accent);
    color: #fff;
  }
  button.primary:hover { background: var(--accent-dark); }
  button.secondary {
    background: #fff;
    border-color: var(--line);
    color: var(--ink);
  }
  button.secondary:hover { background: #f2f4f6; }
  button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
</style>
</head>
<body>
  <div class="card">
    <h1>Termination Form Generator</h1>
    <p class="sub">Enter the Service Number to auto-fill customer details, then generate the termination checklist.</p>

    <form id="form" action="generate.php" method="POST">
      <div class="field">
        <label for="service_number">Service Number (Primary Key)</label>
        <input type="text" id="service_number" name="service_number" placeholder="e.g. 6089889331" autocomplete="off" required>
      </div>
      <div class="status pending" id="status">&nbsp;</div>

      <div class="field">
        <label for="customer_name">Customer Name</label>
        <input type="text" id="customer_name" name="customer_name">
      </div>
      <div class="field">
        <label for="account_no">Account Number</label>
        <input type="text" id="account_no" name="account_no">
      </div>
      <div class="field">
        <label for="tm_segment_code">TM Segment Code</label>
        <input type="text" id="tm_segment_code" name="tm_segment_code">
      </div>
      <div class="field">
        <label for="ic_br_no">IC / BR Number</label>
        <input type="text" id="ic_br_no" name="ic_br_no">
      </div>
      <div class="field">
        <label for="svc_installation_address">Site / Installation Address</label>
        <input type="text" id="svc_installation_address" name="svc_installation_address">
      </div>

      <div class="actions">
        <button type="submit" class="primary" name="format" value="pdf">Generate PDF</button>
        <button type="submit" class="secondary" name="format" value="docx">Generate DOCX</button>
      </div>
    </form>
  </div>

<script>
const serviceNumberInput = document.getElementById('service_number');
const statusEl = document.getElementById('status');
const autoFields = ['customer_name', 'account_no', 'tm_segment_code', 'ic_br_no', 'svc_installation_address'];
const keyMap = {
  customer_name: 'account_name',
  account_no: 'account_no',
  tm_segment_code: 'tm_segment_code',
  ic_br_no: 'ic_br_no',
  svc_installation_address: 'svc_installation_address',
};

let lookupToken = 0;

async function lookup() {
  const value = serviceNumberInput.value.trim();
  if (!value) {
    statusEl.textContent = ' ';
    statusEl.className = 'status pending';
    return;
  }

  const token = ++lookupToken;
  statusEl.textContent = 'Looking up...';
  statusEl.className = 'status pending';

  try {
    const res = await fetch('lookup.php?service_number=' + encodeURIComponent(value));
    const data = await res.json();
    if (token !== lookupToken) return;

    if (data.found) {
      for (const field of autoFields) {
        document.getElementById(field).value = data[keyMap[field]] || '';
      }
      statusEl.textContent = 'Match found — fields auto-filled.';
      statusEl.className = 'status ok';
    } else {
      statusEl.textContent = data.message || 'Service number not found. Fill in the fields manually.';
      statusEl.className = 'status err';
    }
  } catch (e) {
    statusEl.textContent = 'Lookup failed. Fill in the fields manually.';
    statusEl.className = 'status err';
  }
}

serviceNumberInput.addEventListener('blur', lookup);
serviceNumberInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    lookup();
  }
});
</script>
</body>
</html>
