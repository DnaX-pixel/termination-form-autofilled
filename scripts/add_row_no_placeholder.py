"""
Small template tweak: turn the static "1" in the Service Number table's
"No" column into ${row_no}, so DocxExporter can safely clone this row
per service number. Table structure (No | Service Number | Account
Number) and the standalone SITE ADDRESS/SITE NAME field are left as-is.
"""
import zipfile
import shutil
import xml.dom.minidom as minidom

PATH = "templates/termination-form.docx"


def main():
    with zipfile.ZipFile(PATH) as z:
        xml = z.read("word/document.xml").decode("utf-8")

    anchor = 'w14:paraId="60D9E544"'
    idx = xml.find(anchor)
    if idx == -1:
        raise RuntimeError("Data row anchor not found")
    start = xml.rfind("<w:tr ", 0, idx)
    end = xml.find("</w:tr>", idx) + len("</w:tr>")
    row = xml[start:end]

    if row.count("<w:t>1</w:t>") != 1:
        raise RuntimeError(f"Expected exactly 1 literal '1', found {row.count('<w:t>1</w:t>')}")
    new_row = row.replace("<w:t>1</w:t>", "<w:t>${row_no}</w:t>", 1)

    xml = xml[:start] + new_row + xml[end:]

    minidom.parseString(xml)  # validate well-formed

    tmp = PATH + ".tmp"
    with zipfile.ZipFile(PATH) as zin, zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            data = zin.read(item.filename)
            if item.filename == "word/document.xml":
                data = xml.encode("utf-8")
            zout.writestr(item, data)
    shutil.move(tmp, PATH)
    print("OK: ${row_no} placeholder added.")


if __name__ == "__main__":
    main()
