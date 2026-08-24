"""Report how many fonts a PDF page uses and whether all are embedded Type0."""
import sys
from pypdf import PdfReader

fonts = PdfReader(sys.argv[1]).pages[0]["/Resources"]["/Font"]
print(len(fonts))
print(all(str(ref.get_object()["/Subtype"]) == "/Type0" for ref in fonts.values()))
