Flysystem SFTP
==============

For setup instructions see the Flysystem README.txt.

## CONFIGURATION ##

Example configuration:

$schemes = [
  'objectiveexample' => [
    'driver' => 'objective',
    'config' => [
      'username' => 'username',
      'password' => 'password', // Only one of 'password' or 'privatekey' is needed.
      'apiUrl'  =>'****/api/',
      // Optional
      'prefix' => '',
      'public' => TRUE,
    ],
  ],
];

$settings['flysystem'] = $schemes;
