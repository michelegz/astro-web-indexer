<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Frame context fields from FITS headers (see tmp/all-fields-plan.md).
 *
 * - rotator_angle/rotator_name: mechanical rotator state (ROTATOR ?? ROTATANG,
 *   ROTNAME). Feeds the flat rotation signal (phase 2); NULL = not recorded.
 * - readoutm: sensor readout mode (e.g. High Conversion Gain). Stored now,
 *   used by matching later (phase 3).
 * - cloudcvr/dewpoint/humidity/pressure/ambtemp: meteo context, display and
 *   future rejection use only, never matching.
 *
 * All nullable (many drivers do not write them). Portability: Table API only.
 */
final class AddFrameContextFields extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('files')) {
            return;
        }
        $t = $this->table('files');
        $cols = [
            'rotator_angle' => ['type' => 'float', 'null' => true, 'default' => null,
                'comment' => 'Mechanical rotator angle in degrees (ROTATOR ?? ROTATANG)'],
            'rotator_name' => ['type' => 'string', 'limit' => 64, 'null' => true, 'default' => null,
                'comment' => 'Rotator equipment name (ROTNAME)'],
            'readoutm' => ['type' => 'string', 'limit' => 64, 'null' => true, 'default' => null,
                'comment' => 'Sensor readout mode (READOUTM)'],
            'cloudcvr' => ['type' => 'float', 'null' => true, 'default' => null,
                'comment' => 'Cloud cover in percent (CLOUDCVR)'],
            'dewpoint' => ['type' => 'float', 'null' => true, 'default' => null,
                'comment' => 'Dew point in degC (DEWPOINT)'],
            'humidity' => ['type' => 'float', 'null' => true, 'default' => null,
                'comment' => 'Relative humidity in percent (HUMIDITY)'],
            'pressure' => ['type' => 'float', 'null' => true, 'default' => null,
                'comment' => 'Air pressure in hPa (PRESSURE)'],
            'ambtemp' => ['type' => 'float', 'null' => true, 'default' => null,
                'comment' => 'Ambient temperature in degC (AMBTEMP)'],
        ];
        foreach ($cols as $name => $opts) {
            if (!$t->hasColumn($name)) {
                $type = $opts['type'];
                unset($opts['type']);
                $t->addColumn($name, $type, $opts);
            }
        }
        $t->update();
    }
}
