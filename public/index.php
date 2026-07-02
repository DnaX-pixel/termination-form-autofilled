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
    max-width: 760px;
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
  #account_no { font-weight: 600; letter-spacing: 0.02em; }
  .status {
    font-size: 13px;
    margin: -8px 0 20px;
    min-height: 18px;
  }
  .status.ok { color: var(--ok); }
  .status.err { color: var(--err); }
  .status.pending { color: var(--muted); }
  .section-title {
    font-size: 13px;
    font-weight: 700;
    margin: 28px 0 12px;
    border-top: 1px solid var(--line);
    padding-top: 20px;
  }
  .service-row {
    display: grid;
    grid-template-columns: 160px 1fr 32px;
    gap: 8px;
    align-items: start;
    margin-bottom: 8px;
  }
  .service-row input {
    padding: 8px 10px;
    font-size: 13.5px;
  }
  .service-row .remove-btn {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 6px;
    color: var(--err);
    cursor: pointer;
    height: 36px;
    font-size: 16px;
    line-height: 1;
  }
  .service-header {
    display: grid;
    grid-template-columns: 160px 1fr 32px;
    gap: 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 8px;
  }
  .add-row-btn {
    background: none;
    border: 1px dashed var(--line);
    border-radius: 6px;
    color: var(--accent);
    font-size: 13px;
    font-weight: 600;
    padding: 8px 12px;
    cursor: pointer;
    width: 100%;
    margin-top: 4px;
  }
  .add-row-btn:hover { background: #f2f6ff; }
  .actions {
    display: flex;
    gap: 10px;
    margin-top: 28px;
    border-top: 1px solid var(--line);
    padding-top: 24px;
  }
  button.primary, button.secondary {
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
</style>
</head>
<body>
  <div class="card">
    <h1>Termination Form Generator</h1>
    <p class="sub">Enter the Account Number to auto-fill customer details and list all related Service Numbers, then generate the termination checklist.</p>

    <form id="form" action="generate.php" method="POST">
      <div class="field">
        <label for="account_no">Account Number (Primary Key)</label>
        <input type="text" id="account_no" name="account_no" placeholder="e.g. 1040974402" autocomplete="off" required>
      </div>
      <div class="status pending" id="status">&nbsp;</div>

      <div class="field">
        <label for="customer_name">Customer Name</label>
        <input type="text" id="customer_name" name="customer_name">
      </div>
      <div class="field">
        <label for="tm_segment_code">TM Segment Code</label>
        <input type="text" id="tm_segment_code" name="tm_segment_code">
      </div>
      <div class="field">
        <label for="ic_br_no">IC / BR Number</label>
        <input type="text" id="ic_br_no" name="ic_br_no">
      </div>

      <div class="section-title">Service Numbers</div>
      <div class="service-header">
        <span>Service Number</span>
        <span>Installation Address</span>
        <span></span>
      </div>
      <div id="service-rows"></div>
      <button type="button" class="add-row-btn" id="add-row">+ Add Service Number</button>

      <div class="actions">
        <button type="submit" class="primary" name="format" value="pdf">Generate PDF</button>
        <button type="submit" class="secondary" name="format" value="docx">Generate DOCX</button>
      </div>
    </form>
  </div>

<script>
const accountNoInput = document.getElementById('account_no');
const statusEl = document.getElementById('status');
const accountFields = ['customer_name', 'tm_segment_code', 'ic_br_no'];
const rowsContainer = document.getElementById('service-rows');

function addServiceRow(serviceNumber = '', address = '') {
  const row = document.createElement('div');
  row.className = 'service-row';
  row.innerHTML = `
    <input type="text" name="service_number[]" placeholder="Service Number">
    <input type="text" name="svc_installation_address[]" placeholder="Installation Address">
    <button type="button" class="remove-btn" title="Remove">&times;</button>
  `;
  row.querySelector('input[name="service_number[]"]').value = serviceNumber;
  row.querySelector('input[name="svc_installation_address[]"]').value = address;
  row.querySelector('.remove-btn').addEventListener('click', () => row.remove());
  rowsContainer.appendChild(row);
}

function clearServiceRows() {
  rowsContainer.innerHTML = '';
}

document.getElementById('add-row').addEventListener('click', () => addServiceRow());

let lookupToken = 0;

async function lookup() {
  const value = accountNoInput.value.trim();
  if (!value) {
    statusEl.textContent = ' ';
    statusEl.className = 'status pending';
    return;
  }

  const token = ++lookupToken;
  statusEl.textContent = 'Looking up...';
  statusEl.className = 'status pending';

  try {
    const res = await fetch('lookup.php?account_no=' + encodeURIComponent(value));
    const data = await res.json();
    if (token !== lookupToken) return;

    if (data.found) {
      document.getElementById('customer_name').value = data.account_name || '';
      document.getElementById('tm_segment_code').value = data.tm_segment_code || '';
      document.getElementById('ic_br_no').value = data.ic_br_no || '';

      clearServiceRows();
      const services = data.services || [];
      if (services.length > 0) {
        for (const s of services) {
          addServiceRow(s.service_number || '', s.svc_installation_address || '');
        }
        statusEl.textContent = `Match found — ${services.length} service number(s) auto-filled.`;
      } else {
        addServiceRow();
        statusEl.textContent = 'Account found but no service numbers on file — add manually.';
      }
      statusEl.className = 'status ok';
    } else {
      statusEl.textContent = (data.message || 'Account number not found.') + ' Fill in the fields manually.';
      statusEl.className = 'status err';
      if (rowsContainer.children.length === 0) {
        addServiceRow();
      }
    }
  } catch (e) {
    statusEl.textContent = 'Lookup failed. Fill in the fields manually.';
    statusEl.className = 'status err';
  }
}

accountNoInput.addEventListener('blur', lookup);
accountNoInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    lookup();
  }
});

// Start with one empty row so the form is usable before any lookup
addServiceRow();
</script>
</body>
</html>
