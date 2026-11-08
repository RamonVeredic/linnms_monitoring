"""The master synchronization workflow must update only frontend files."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MASTER = (ROOT / ".github/workflows/input-sync.yml").read_text(encoding="utf-8")
FRONTEND_EXPORT = (ROOT / "scripts/master.py").read_text(encoding="utf-8")
BUILD = (ROOT / ".github/workflows/linnms_monitoring.yml").read_text(encoding="utf-8")


class MasterContractTest(unittest.TestCase):
    def test_sync_writes_only_frontend(self):
        self.assertIn('rsync -a --delete --exclude=vendor/ "$export_root/config/www/" config/www/', MASTER)
        self.assertIn("git add -A -- config/www", MASTER)
        self.assertNotIn("git add -A -- config nginx", MASTER)
        self.assertNotIn("git add -A .", MASTER)
        self.assertIn('test ! -d "$export_root/config/app"', MASTER)
        self.assertIn('test ! -d "$export_root/nginx"', MASTER)
        self.assertIn('test ! -d "$export_root/linnms.init.d"', MASTER)
        self.assertIn('test ! -d "$export_root/linnms.service.d"', MASTER)
        self.assertIn("FRONTEND_TREE =", FRONTEND_EXPORT)
        self.assertIn('"config/www"', FRONTEND_EXPORT)
        self.assertIn("http://127.0.0.1/internal-api.php", FRONTEND_EXPORT)

    def test_no_vendor_export(self):
        self.assertIn("--exclude=vendor/", MASTER)
        self.assertIn("-type d -name vendor -print -quit", MASTER)
        self.assertIn('"vendor"', FRONTEND_EXPORT)

    def test_source_commit_and_dispatch_unchanged(self):
        self.assertIn("name: input-sync", MASTER)
        self.assertIn("run-name: input-sync", MASTER)
        self.assertIn("jobs:\n  master:\n    name: master", MASTER)
        self.assertIn("workflow_dispatch:", MASTER)
        self.assertIn("repository_dispatch:", MASTER)
        self.assertIn("types: [master]", MASTER)
        self.assertIn("github.event.client_payload.sha", MASTER)
        self.assertIn('git -C "$work/source" merge-base --is-ancestor "$SOURCE_SHA" origin/master', MASTER)
        self.assertIn('git -C "$work/source" log -1 --format=%s', MASTER)
        self.assertIn('git commit -m "$subject" -m "Revision: ${revision}"', MASTER)
        self.assertIn("git config user.name 'planetsrv'", MASTER)
        self.assertIn("git config user.email 'mondaiajah1w@gmail.com'", MASTER)
        self.assertNotIn("github-actions[bot]", MASTER)
        self.assertNotIn('git commit -m "Update application frontend"', MASTER)
        self.assertNotIn('Source: LINNMS-Staging@', MASTER)
        self.assertIn("git diff --cached --quiet", MASTER)
        self.assertIn("permissions:\n  contents: write", MASTER)
        self.assertIn("secrets.LINNMS_MONITORING_TOKEN_GIT", MASTER)

    def test_image_still_reads_staging_master_directly(self):
        self.assertIn("github.com/${TARGET_INPUT}.git", BUILD)
        self.assertIn("TARGET_INPUT: RamonVeredic/linnms_deplov", MASTER)
        self.assertIn("TARGET_INPUT: RamonVeredic/linnms_deplov", BUILD)
        self.assertIn("LINNMS_MODUL: RamonVeredic/linnms-protect", BUILD)
        self.assertIn("clone -q --depth 1 --branch master", BUILD)
        self.assertIn('python3 scripts/release.py "$work/source" "$work/plain"', BUILD)
        self.assertIn("uses: docker/build-push-action@v6", BUILD)


if __name__ == "__main__":
    unittest.main()
