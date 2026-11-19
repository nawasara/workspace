<?php

namespace Nawasara\Workspace\Support;

/**
 * The old Peserta Pilot page edits this package's own table. Once Akses
 * Layanan Kisara owns the list (KisaraAccessBridge::active), every write
 * from that page is refused: two editable lists would drift apart, and the
 * one Workspace no longer reads would look like it works.
 */
trait GuardsLegacyPilotList
{
    protected function refuseWhenKisaraAccessOwnsTheList(): void
    {
        abort_if(app(KisaraAccessBridge::class)->active(), 409, 'Daftar peserta kini dikelola di Akses Layanan Kisara.');
    }
}
