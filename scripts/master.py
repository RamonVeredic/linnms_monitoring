"""Export the application frontend for publication."""
from __future__ import annotations

import pathlib
import shutil
import sys

FRONTEND_TREE = pathlib.PurePosixPath("config/www")
EXCLUDED_NAMES = {".git", ".github", "__pycache__", "vendor"}
REQUIRED_FRONTEND_FILES = (
    "index.php",
    "entrypoints/index.php",
    "app/internal-api-client.php",
)
BRIDGE_ENDPOINT = "http://127.0.0.1/internal-api.php"


def mirror(source: pathlib.Path, destination: pathlib.Path) -> None:
    source = source.resolve(strict=True)
    destination = destination.resolve()
    if (
        destination.exists()
        or destination.is_relative_to(source)
        or source.is_relative_to(destination)
    ):
        raise ValueError("Refusing existing or overlapping export directory")

    frontend = source.joinpath(*FRONTEND_TREE.parts)
    if not frontend.is_dir() or frontend.is_symlink():
        raise ValueError("Missing or unsafe frontend directory: config/www")
    missing = [
        name for name in REQUIRED_FRONTEND_FILES
        if not (frontend / name).is_file() or (frontend / name).is_symlink()
    ]
    if missing:
        raise ValueError("Missing frontend/API bridge files: " + ", ".join(missing))

    api_client = (frontend / "app/internal-api-client.php").read_text(encoding="utf-8")
    if "function internal_api_request(" not in api_client or BRIDGE_ENDPOINT not in api_client:
        raise ValueError("Required application bridge is unavailable")

    destination.mkdir(parents=True, exist_ok=False)
    export_frontend = destination.joinpath(*FRONTEND_TREE.parts)
    export_frontend.mkdir(parents=True, exist_ok=True)

    for path in frontend.rglob("*"):
        relative = path.relative_to(frontend)
        if any(part in EXCLUDED_NAMES or part.startswith(".") for part in relative.parts):
            continue
        if path.is_symlink():
            raise ValueError(f"Symlinks not permitted: {relative}")
        target = export_frontend / relative
        if path.is_dir():
            target.mkdir(parents=True, exist_ok=True)
        elif path.is_file():
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: master.py SOURCE EXPORT")
    mirror(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]))
