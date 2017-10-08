<?php

return [
    'connections' => [
        'mongodb'   => [
            'driver'        => 'mongodb',
            'dsn'           => 'mongodb://localhost:27017',
            'database'      => 'mydb', // Default DB to perform queries against (not authenticate against)
            'retryConnect'  => 2, // Number of connection retry attempts before failing (doctrine feature)
            'retryQuery'    => 1, // Number of query retry attempts before failing (doctrine feature)
            'options'       => [
                // mapped to MongoClient $options
                'connectTimeoutMS'  => 1000, // Connection attempt timeout (milliseconds)
                'wTimeoutMS'        => 2500, // DB write attempt timeout (milliseconds)
                'socketTimeoutMS'   => 10000, // Client side socket timeout (milliseconds)
                'w'                 => 'majority', // Default write concern (normally w=1)
                'readPreference'    => \MongoClient::RP_PRIMARY_PREFERRED, // Default read preference
            ],
            'driverOptions' => [ // mapped to MongoClient $driverOptions (e.g. for SSL stream context)

            ]
        ]
    ]
];
