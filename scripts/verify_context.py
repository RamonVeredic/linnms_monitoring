"""Check image build."""
from __future__ import annotations
import pathlib
import sys

build_context = pathlib.Path(sys.argv[1]).resolve(strict=True)
if not (build_context / "bundle/config/www/index.php").is_file():
    raise SystemExit("E01")
if not (build_context / "pq/loader/nx.c").is_file():
    raise SystemExit("E02")
header = build_context / "pq/loader/build.h"
if not header.is_file() or "#define NX_BUILD_DATA 1" not in header.read_text():
    raise SystemExit("E03")
deny = {".git", ".github", "docker", "tests", "tools", "source-seed", "doc",
        "linnms-staging", "staging", "__pycache__"}
target_file = pathlib.Path("bundle/config/app/runtime/vendor")
if not (build_context / target_file).is_dir():
    raise SystemExit("E04")
file_count = 0
for file in build_context.rglob("*"):
    if file.is_symlink():
        raise SystemExit("E05")
    if not file.is_file():
        continue
    rel = file.relative_to(build_context)
    if rel.parts[0] not in {"bundle", "pq"} and rel.as_posix() != "Dockerfile":
        raise SystemExit("E09")
    if rel.parts[0] == "pq" and rel.suffix not in {".c", ".h", ".m4"}:
        raise SystemExit("E06")
    if rel.parts[0] == "bundle":
        if any(part.lower() in deny or part.startswith(".") for part in rel.parts):
            raise SystemExit("E07")
        if rel.suffix.lower() == ".php":
            file_count += 1
            with file.open("rb") as source_php:
                if source_php.read(4) != b"LNC1":
                    raise SystemExit("E08")
        if rel.name.startswith("Dockerfile") or rel.suffix.lower() in {".yml", ".yaml", ".sample"}:
            raise SystemExit("E10")
if file_count < 20:
    raise SystemExit("E11")
init = (build_context / "bundle/linnms.init.d/50-linnms-public-init").read_text(encoding="utf-8")
if any(s in init for s in ("SOURCE_MOUNTS", "SOURCE_SEED", "SOURCE_ROOT", "staging", "source-seed")):
    raise SystemExit("E12")
print(f"OK {file_count}")
