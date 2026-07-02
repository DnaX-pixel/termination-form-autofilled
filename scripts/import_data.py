"""
One-time (re-runnable) import: extract the columns needed by the
termination form from the 'output' sheet of SERVICE LEVEL xlsx into a
compact JSON lookup file keyed by Account Number (primary key).

One account can have multiple Service Numbers, each with its own
installation address; account_name/ic_br_no/tm_segment_code are
account-level (verified consistent across all services of an account
in the source data) and stored once per account.

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

    idx = {}
    accounts = {}
    total_rows = 0
    duplicate_services = 0

    for i, row in enumerate(ws.iter_rows(values_only=True)):
        if i == 0:
            header = row
            for name in needed:
                idx[name] = header.index(name)
            continue

        account_no = clean(row[idx["Account No"]])
        service_number = clean(row[idx["Service Number"]])
        if not account_no or not service_number:
            continue

        total_rows += 1

        account = accounts.setdefault(account_no, {
            "account_name": clean(row[idx["Account Name"]]),
            "ic_br_no": clean(row[idx["IC / BR No"]]),
            "tm_segment_code": clean(row[idx["TM Segment Code"]]),
            "services": {},
        })

        if service_number in account["services"]:
            duplicate_services += 1

        account["services"][service_number] = {
            "service_number": service_number,
            "svc_installation_address": clean(row[idx["SVC Installation Address"]]),
        }

    # Flatten services dict -> list, in first-seen order
    for account in accounts.values():
        account["services"] = list(account["services"].values())

    out_path = os.path.join(SCRIPT_DIR, "..", "data", "raw_data.json")
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(accounts, f, ensure_ascii=False)

    total_services = sum(len(a["services"]) for a in accounts.values())
    print(f"Rows scanned: {total_rows}")
    print(f"Unique accounts: {len(accounts)}")
    print(f"Unique service numbers: {total_services}")
    print(f"Duplicate (account, service_number) rows overwritten by last occurrence: {duplicate_services}")
    print(f"Written to: {out_path}")


if __name__ == "__main__":
    main()
