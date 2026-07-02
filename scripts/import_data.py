"""
One-time (re-runnable) import: extract the 6 columns needed by the
termination form from the 'output' sheet of SERVICE LEVEL xlsx into a
compact JSON lookup file keyed by Service Number (primary key).

Usage:
    python scripts/import_data.py "SERVICE LEVEL 26062026.xlsx"
"""
import os
import sys
import json
import openpyxl

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))


def clean(value):
    if value is None:
        return ""
    if isinstance(value, float):
        if value.is_integer():
            return str(int(value))
        return str(value)
    return str(value).strip()


def main():
    if len(sys.argv) != 2:
        print("Usage: python import_data.py <xlsx-path>", file=sys.stderr)
        sys.exit(1)

    xlsx_path = sys.argv[1]
    wb = openpyxl.load_workbook(xlsx_path, read_only=True, data_only=True)
    ws = wb["output"]

    needed = [
        "Service Number",
        "Account No",
        "Account Name",
        "IC / BR No",
        "TM Segment Code",
        "SVC Installation Address",
    ]
    key_map = {
        "Service Number": "service_number",
        "Account No": "account_no",
        "Account Name": "account_name",
        "IC / BR No": "ic_br_no",
        "TM Segment Code": "tm_segment_code",
        "SVC Installation Address": "svc_installation_address",
    }

    idx = {}
    result = {}
    duplicates = 0
    total = 0

    for i, row in enumerate(ws.iter_rows(values_only=True)):
        if i == 0:
            header = row
            for name in needed:
                idx[name] = header.index(name)
            continue

        sn = clean(row[idx["Service Number"]])
        if not sn:
            continue

        total += 1
        if sn in result:
            duplicates += 1

        result[sn] = {
            key_map[name]: clean(row[idx[name]])
            for name in needed
            if name != "Service Number"
        }

    out_path = os.path.join(SCRIPT_DIR, "..", "data", "raw_data.json")
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False)

    print(f"Rows scanned: {total}")
    print(f"Unique service numbers: {len(result)}")
    print(f"Duplicate service numbers (overwritten by last occurrence): {duplicates}")
    print(f"Written to: {out_path}")


if __name__ == "__main__":
    main()
