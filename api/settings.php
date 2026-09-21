<?php
/* Public settings the front end reads on load (trial length, founding slots) */
require __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');
json_out([
  'trial_days'    => (int) setting('trial_days', 14),
  'founding_on'   => (int) setting('founding_on', 1) === 1,
  'founding_pct'  => (int) setting('founding_pct', 25),
  'founding_slots'=> (int) setting('founding_slots', 3),
  'founding_left' => (int) setting('founding_left', 3),
  'popups_on'     => (int) setting('popups_on', 1) === 1
]);
