<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2012 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Setup\Version\Migration;

use Alchemy\Phrasea\Application;

class Migration31
{
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function migrate()
    {
        $app = $this->app;
        require __DIR__ . '/../../../../../../config/_GV.php';
        require __DIR__ . '/../../../../../../lib/conf.d/_GV_template.inc';

        $retrieve_old_credentials = function() {
                require __DIR__ . '/../../../../../../config/connexion.inc';

                return array(
                    'hostname' => $hostname,
                    'port'     => $port,
                    'user'     => $user,
                    'password' => $password,
                    'dbname'   => $dbname,
                );
            };

        $params = $retrieve_old_credentials();

        $dsn = 'mysql:dbname=' . $params['dbname'] . ';host=' . $params['hostname'] . ';port=' . $params['port'] . ';';
        $connection = new \PDO($dsn, $params['user'], $params['password']);

        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $connection->query("
            SET character_set_results = 'utf8', character_set_client = 'utf8',
            character_set_connection = 'utf8', character_set_database = 'utf8',
            character_set_server = 'utf8'");


        $sql = 'REPLACE INTO registry (`id`, `key`, `value`, `type`)
            VALUES (null, :key, :value, :type)';
        $stmt = $connection->prepare($sql);

        foreach ($GV as $section => $datas_section) {
            foreach ($datas_section['vars'] as $datas) {

                eval('$test = defined("' . $datas["name"] . '");');
                if (!$test) {
                    continue;
                }
                eval('$val = ' . $datas["name"] . ';');

                $val = $val === true ? '1' : $val;
                $val = $val === false ? '0' : $val;

                $type = $datas['type'];
                switch ($datas['type']) {
                    case registry::TYPE_ENUM_MULTI:
                    case registry::TYPE_ARRAY:
                        $val = serialize($val);
                        break;
                    case registry::TYPE_INTEGER:
                        break;
                    case registry::TYPE_BOOLEAN:
                        $val = $val ? '1' : '0';
                        break;
                    case registry::TYPE_STRING:
                        $val = (int) $val;
                    default:
                        $val = (string) $val;
                        $type = \registry::TYPE_STRING;
                        break;
                }

                $stmt->execute(array(
                    ':key'   => $datas['name'],
                    ':value' => $val,
                    ':type'  => $type,
                ));
            }
        }
        $stmt->closeCursor();

        rename(__DIR__ . '/../../../../../../config/_GV.php', __DIR__ . '/../../../../../../config/_GV.php.old');

        $servername = '';
        if (defined('GV_ServerName')) {
            $servername = GV_ServerName;
        }

        file_put_contents(__DIR__ . '/../../../../../../config/config.inc', "<?php\n\$servername = \"" . str_replace('"', '\"', $servername) . "\";\n");

        return;
    }
}
