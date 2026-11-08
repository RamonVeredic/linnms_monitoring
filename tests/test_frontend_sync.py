"""Frontend-only synchronization must never export the private backend."""
from pathlib import Path
import tempfile
import unittest

from scripts.master import mirror


class FrontendSyncTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.staging = self.root / "staging"
        self.staging.mkdir()
        self.export = self.root / "export"
        self.put("config/www/index.php", "<?php require __DIR__ . '/entrypoints/index.php';")
        self.put("config/www/entrypoints/index.php", "<?php echo 'frontend';")
        self.put(
            "config/www/app/internal-api-client.php",
            "<?php function internal_api_request($a, $b) { "
            "return curl_init('http://127.0.0.1/internal-api.php'); }",
        )
        self.put("config/www/assets/css/site.css", "body{color:red}")
        self.put("config/www/view/dashboard.php", "<h1>Dashboard</h1>")
        self.put("config/www/vendor/dependency.php", "SHOULD_NOT_SYNC")
        self.put("config/www/.env", "SHOULD_NOT_SYNC")
        self.put("config/app/runtime/backend/api.php", "PRIVATE_BACKEND")
        self.put("config/app/config/database.php", "PRIVATE_DB")
        self.put("nginx/site-confs/default.conf", "PRIVATE_NGINX")
        self.put("linnms.init.d/50-linnms-public-init", "PRIVATE_INIT")
        self.put("linnms.service.d/linnms-workers", "PRIVATE_SERVICE")
        self.put("docker/Dockerfile", "PRIVATE_BUILD")

    def put(self, rel: str, content: str) -> None:
        target = self.staging / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")

    def test_exports_frontend_and_local_api_bridge_only(self):
        mirror(self.staging, self.export)
        copied = sorted(
            str(p.relative_to(self.export))
            for p in self.export.rglob("*") if p.is_file()
        )
        self.assertEqual(copied, [
            "config/www/app/internal-api-client.php",
            "config/www/assets/css/site.css",
            "config/www/entrypoints/index.php",
            "config/www/index.php",
            "config/www/view/dashboard.php",
        ])
        self.assertIn(
            "http://127.0.0.1/internal-api.php",
            (self.export / "config/www/app/internal-api-client.php").read_text(),
        )
        self.assertFalse((self.export / "config/app").exists())
        self.assertFalse((self.export / "nginx").exists())

    def test_missing_local_api_bridge_fails_closed(self):
        self.put("config/www/app/internal-api-client.php", "<?php call_remote_api();")
        with self.assertRaisesRegex(ValueError, "Required application bridge"):
            mirror(self.staging, self.export)
        self.assertFalse(self.export.exists())

    def test_missing_entrypoint_fails_closed(self):
        (self.staging / "config/www/index.php").unlink()
        with self.assertRaisesRegex(ValueError, "Missing frontend/API bridge"):
            mirror(self.staging, self.export)

    def test_symlink_inside_frontend_is_rejected(self):
        asset = self.staging / "config/www/assets/css/escape.css"
        asset.symlink_to(self.staging / "config/app/config/database.php")
        with self.assertRaisesRegex(ValueError, "Symlinks not permitted"):
            mirror(self.staging, self.export)

    def test_export_cannot_overlap_source(self):
        with self.assertRaisesRegex(ValueError, "overlapping"):
            mirror(self.staging, self.staging / "out")


if __name__ == "__main__":
    unittest.main()
