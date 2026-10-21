# Local process interruption

VersionBuilder stages content, inventory and checksum in a sibling temporary
directory, then renames the directory to vN. It writes the root inventory to a
sibling temporary file and renames it over inventory.json. InventorySidecar now
also writes a sibling temporary file and renames it over the checksum file.

This prevents a partial checksum write from destroying the previous checksum.
It does **not** make the inventory/checksum pair atomic. Between the two root
renames, open() rejects their mismatch. The previous checksum can identify the
previous version's immutable inventory, if retained and independently verified.
Callers must define their own acknowledgement and reconciliation policy.
A complete vN directory alone does not prove that root publication completed.

No automatic cleanup or recovery is introduced here. A killed writer can leave
partial temporary files, an unacknowledged vN, or mismatched root metadata.
Preserve these files for inspection; do not infer a commit from directory order.
Callers must serialize writers. Atomic replacement requires same-filesystem
rename semantics; remote/object-store adapters may not provide them.

Run `php tests/crash-sidecar.php` (POSIX, ext-posix, proc_open). It uses the
library directly, kills a separate writer with SIGKILL during the physical
sidecar write, and checks the previous checksum is byte-for-byte intact. It
fails on the old in-place write. Normal replacement is also checked.

No fsync or directory synchronization is added. SIGKILL coverage proves local
process-crash behavior on the tested filesystem, not power-loss durability.
