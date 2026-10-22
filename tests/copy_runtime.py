"""Create and remove disposable application copies for lifecycle tests."""
from __future__ import annotations
import shutil
import sys
import tempfile
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1]
MARKER = ".vvx-test-runtime"
if len(sys.argv) == 3 and sys.argv[1] == "--remove":
    candidate = Path(sys.argv[2]).resolve()
    if candidate == SOURCE or not candidate.name.startswith("vvx-test-") or not (candidate / MARKER).is_file():
        raise SystemExit("Refusing to remove an unmarked application directory.")
    shutil.rmtree(candidate)
elif len(sys.argv) == 1:
    target = Path(tempfile.mkdtemp(prefix="vvx-test-"))
    shutil.copytree(SOURCE, target, dirs_exist_ok=True,
                    ignore=shutil.ignore_patterns(".git", "node_modules", "__pycache__", "test-results", "artifacts", MARKER))
    (target / MARKER).write_text("Disposable automated test runtime.\n", encoding="utf-8")
    if not (target / "config/app.php").exists():
        shutil.copy2(target / "config/app.example.php", target / "config/app.php")
    print(target)
else:
    raise SystemExit("Usage: copy_runtime.py [--remove PATH]")
