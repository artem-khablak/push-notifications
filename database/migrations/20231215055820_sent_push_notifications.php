<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SentPushNotifications extends AbstractMigration
{
    public function up(): void
    {
        $this->table('sent_push_notifications')
            ->addColumn('status', 'set', ['values' => ['queued', 'sent', 'failed']])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addColumn('notification_id', 'integer')
            ->addColumn('device_id', 'integer')
            ->addIndex(['notification_id', 'device_id'])
            ->addForeignKey(
                'device_id',
                'devices',
                'id',
                [
                    'delete'=> 'NO_ACTION',
                    'update'=> 'NO_ACTION',
                    'constraint' => 'sent_push_notifications_device_id',
                ]
            )
            ->addForeignKey(
                'notification_id',
                'push_notifications',
                'id',
                [
                    'delete'=> 'NO_ACTION',
                    'update'=> 'NO_ACTION',
                    'constraint' => 'sent_push_notifications_notification_id',
                ]
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('sent_push_notifications')
            ->drop();
    }
}
