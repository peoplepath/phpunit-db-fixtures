<?php


use Phinx\Seed\AbstractSeed;

class DemoSeeder extends AbstractSeed
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * http://docs.phinx.org/en/latest/seeding.html
     */
    public function run()
    {
        $data = [
            [
                'username' => 'user1',
                'created'  => '2011-11-11 11:11:11',
            ],
            [
                'username' => 'user2',
                'created'  => '2012-12-12 12:12:12',
            ],
        ];

        $posts = $this->table('demo');
        $posts->insert($data)
              ->save();
    }
}
