#!/usr/bin/env python3
"""Build a clean, installable WordPress ZIP without development fixtures."""
from pathlib import Path
import zipfile

root = Path(__file__).resolve().parents[1]
output = root / 'dist' / 'banner-studio-1.1.0.zip'
files = [root / name for name in ('banner-ad-plugin.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE')]
for directory in ('includes', 'assets'):
    files.extend(sorted(path for path in (root / directory).rglob('*') if path.is_file()))
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in files:
        info = zipfile.ZipInfo('banner-studio/' + path.relative_to(root).as_posix())
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, path.read_bytes())
print(output)
