<?php
// The SFF filter catalogue and the filters that apply to each search type.
//
// This used to live as a literal array inside api/sff_get_filters.php. The search
// endpoint needs the same list, because it has to validate the filter ids it is given
// before interpolating them as column names, and it cannot include the endpoint that
// declared it: that file echoes HTML on load. So the list was referenced from there as
// an undefined $allFilters, and every search carrying at least one filter died with a
// TypeError in array_keys() before reaching the database.
//
// One definition, required by both callers.

/**
 * Every column a calibration search can filter on, with its UI metadata.
 *
 * @return array<string, array<string, mixed>>
 */
function sff_all_filters(): array
{
    return [
        // Common
        'instrume'   => ['label' => __('instrume'),   'type' => 'toggle', 'default_on' => true],
        'cameraid'   => ['label' => __('camera_id'),    'type' => 'toggle', 'default_on' => true],
        'ccd_temp'   => ['label' => __('ccd_temp'),     'type' => 'slider_degrees', 'default_on' => true, 'min' => 0, 'max' => 30, 'step' => 1, 'unit' => '°', 'default_tolerance' => 2],
        'xbinning'   => ['label' => __('xbinning'),    'type' => 'toggle', 'default_on' => true],
        'ybinning'   => ['label' => __('ybinning'),    'type' => 'toggle', 'default_on' => true],
        'width'      => ['label' => __('dimensions_width'),        'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
        'height'     => ['label' => __('dimensions_height'),       'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
        'date_obs'   => ['label' => __('date_obs'),    'type' => 'slider_days',    'default_on' => false, 'min' => 0, 'max' => 365, 'step' => 1, 'unit' => 'd', 'default_tolerance' => 30],

        // Light specific
        'object'     => ['label' => __('object'),       'type' => 'toggle', 'default_on' => true],
        'filter'     => ['label' => __('filter'),       'type' => 'toggle', 'default_on' => true],
        'exptime'    => ['label' => __('exposure'),     'type' => 'slider_percent', 'default_on' => true, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 10],
        'ra'         => ['label' => __('ra'),           'type' => 'slider_degrees', 'default_on' => false, 'min' => 0, 'max' => 15, 'step' => 1, 'unit' => '°', 'default_tolerance' => 1],
        'dec'        => ['label' => __('dec'),          'type' => 'slider_degrees', 'default_on' => false, 'min' => 0, 'max' => 15, 'step' => 1, 'unit' => '°', 'default_tolerance' => 1],
        'objctrot'   => ['label' => __('rotation'),     'type' => 'slider_degrees', 'default_on' => false, 'min' => 0, 'max' => 180, 'step' => 1, 'unit' => '°', 'default_tolerance' => 2],
        'fov_w'      => ['label' => __('fov_width'),    'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
        'fov_h'      => ['label' => __('fov_height'),   'type' => 'slider_percent', 'default_on' => false, 'min' => 0, 'max' => 50, 'step' => 1, 'unit' => '%', 'default_tolerance' => 5],
        'moon_phase' => ['label' => __('moon_phase'),   'type' => 'slider_absolute', 'default_on' => false, 'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '', 'default_tolerance' => 30],

        // Flat specific ('filter' is already part of the common group)
    ];
}

/**
 * Which of the catalogue's filters apply to each search type.
 *
 * @return array<string, string[]>
 */
function sff_filters_for_type(): array
{
    return [
        'lights' => ['object', 'filter', 'instrume', 'cameraid', 'exptime', 'ccd_temp', 'xbinning', 'ybinning', 'ra', 'dec', 'objctrot', 'fov_w', 'fov_h', 'moon_phase', 'width', 'height', 'date_obs'],
        'bias'   => ['instrume', 'cameraid', 'ccd_temp', 'xbinning', 'ybinning', 'width', 'height', 'date_obs'],
        'darks'  => ['instrume', 'cameraid', 'exptime', 'ccd_temp', 'xbinning', 'ybinning', 'width', 'height', 'date_obs'],
        'flats'  => ['filter', 'instrume', 'cameraid', 'ccd_temp', 'xbinning', 'ybinning', 'objctrot', 'width', 'height', 'date_obs'],
    ];
}