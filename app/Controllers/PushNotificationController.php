<?php

namespace App\Controllers;

use App\Models\PushNotification;

class PushNotificationController extends Controller
{
    /**
     * @throws \Exception
     * @api {post} / Request to send
     *
     * @apiVersion 0.1.0
     * @apiName send
     * @apiDescription This method saves the push notification and put it to the queue.
     * @apiGroup Sending
     *
     * @apiBody {string="send"} action API method
     * @apiBody {string} title Title of push notification
     * @apiBody {string} message Message of push notification
     * @apiBody {int} country_id Country ID
     *
     * @apiParamExample {json} Request-Example:
     * {"action":"send","title":"Hello","message":"World","country_id":4}
     *
     * @apiSuccessExample {json} Success:
     * {"success":true,"result":{"notification_id":123}}
     *
     * @apiErrorExample {json} Failed:
     * {"success":false,"result":null}
     */
    public function sendByCountryId(string $title, string $message, int $countryId): ?array
    {
        // Begin transaction to ensure atomicity
        $this->pdo->beginTransaction();

        try {
            // Insert the notification into push_notifications
            $notificationSql = "INSERT INTO push_notifications (title, message, country_id) 
                                 VALUES (:title, :message, :countryId)";
            $notificationStmt = $this->pdo->prepare($notificationSql);
            $notificationStmt->execute([
                ':title' => $title,
                ':message' => $message,
                ':countryId' => $countryId
            ]);

            // Get the last inserted ID
            $notificationId = $this->pdo->lastInsertId();

            // Prepare the SQL to insert queued notifications for each device
            $deviceSql = "INSERT INTO sent_push_notifications (notification_id, device_id, status, sent_at) 
                          SELECT :notificationId, id, 'queued', NULL FROM devices 
                          WHERE user_id IN (SELECT id FROM users WHERE country_id = :countryId)
                          AND expired = 0";
            $deviceStmt = $this->pdo->prepare($deviceSql);
            $deviceStmt->bindParam(':notificationId', $notificationId, \PDO::PARAM_INT);
            $deviceStmt->bindParam(':countryId', $countryId, \PDO::PARAM_INT);
            $deviceStmt->execute();

            // Commit the transaction
            $this->pdo->commit();

            return [
                'notification_id' => $notificationId,
            ];
        } catch (\Exception $e) {
            // Rollback the transaction in case of an error
            $this->pdo->rollback();

            // Handle the exception (log it, rethrow it, or return an error response)
            throw $e;
        }
    }

    /**
     * @throws \Exception
     * @api {post} / Get details
     *
     * @apiVersion 0.1.0
     * @apiName details
     * @apiDescription This method returns all details by notification ID.
     * @apiGroup Information
     *
     * @apiBody {string="details"} action API method
     * @apiBody {int} notification_id Notification ID
     *
     * @apiParamExample {json} Request-Example:
     * {"action":"details","notification_id":123}
     *
     * @apiSuccessExample {json} Success:
     * {"success":true,"result":{"id":123,"title":"Hello","message":"World","sent":90000,"failed":10000,"in_progress":100000,"in_queue":123456}}
     *
     * @apiErrorExample {json} Notification not found:
     * {"success":false,"result":null}
     */
    public function details(int $notificationId): ?array
    {
        try {
            // Prepare the SQL statement to retrieve the push notification's basic info
            $notificationSql = "SELECT title, message FROM push_notifications WHERE id = :notificationId";
            $notificationStmt = $this->pdo->prepare($notificationSql);
            $notificationStmt->bindParam(':notificationId', $notificationId, \PDO::PARAM_INT);
            $notificationStmt->execute();
            $notificationDetails = $notificationStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$notificationDetails) {
                throw new \Exception('Notification not found.');
            }

            // Prepare the SQL statement to count the statuses of sent notifications
            $statusSql = "SELECT status, COUNT(*) as count FROM sent_push_notifications 
                          WHERE notification_id = :notificationId GROUP BY status";
            $statusStmt = $this->pdo->prepare($statusSql);
            $statusStmt->bindParam(':notificationId', $notificationId, \PDO::PARAM_INT);
            $statusStmt->execute();
            $statusCounts = $statusStmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            // Set initial counts
            $sent = $statusCounts['sent'] ?? 0;
            $failed = $statusCounts['failed'] ?? 0;
            $queued = $statusCounts['queued'] ?? 0;

            // Assuming 'in_progress' is determined by a specific condition such as a NULL 'sent_at' field
            // Adjust the below SQL as necessary based on your application logic
            $inProgressSql = "SELECT COUNT(*) as count FROM sent_push_notifications 
                              WHERE notification_id = :notificationId AND status = 'sent' AND sent_at IS NULL";
            $inProgressStmt = $this->pdo->prepare($inProgressSql);
            $inProgressStmt->bindParam(':notificationId', $notificationId, \PDO::PARAM_INT);
            $inProgressStmt->execute();
            $inProgressCount = $inProgressStmt->fetchColumn();

            return [
                'id' => $notificationId,
                'title' => $notificationDetails['title'],
                'message' => $notificationDetails['message'],
                'sent' => $sent,
                'failed' => $failed,
                'in_progress' => $inProgressCount,
                'in_queue' => $queued,
            ];
        } catch (\Exception $e) {
            // Handle the exception (log it, rethrow it, or return an error response)
            throw $e;
        }
    }

    /**
     * @throws \Exception
     * @api {post} / Sending by CRON
     *
     * @apiVersion 0.1.0
     * @apiName cron
     * @apiDescription This method sends the push notifications from queue.
     * @apiGroup Sending
     *
     * @apiBody {string="cron"} action API method
     *
     * @apiParamExample {json} Request-Example:
     * {"action":"cron"}
     *
     * @apiSuccessExample {json} Success and sent:
     * {"success":true,"result":[{"notification_id":123,"title":"Hello","message":"World","sent":50000,"failed":10000},{"notification_id":124,"title":"New","message":"World","sent":20000,"failed":20000}]}
     *
     * @apiSuccessExample {json} Success, no notifications in the queue:
     * {"success":true,"result":[]}
     */
    public function cron(): array
    {
        $summaries = [];
        $totalProcessed = 0;

        try {
            while (true) {
                $this->pdo->beginTransaction();

                // Updated SQL to retrieve the device token along with other details
                $sql = "SELECT pn.id, pn.title, pn.message, d.token, spn.id as sent_notification_id
                FROM push_notifications pn
                JOIN sent_push_notifications spn ON pn.id = spn.notification_id
                JOIN devices d ON spn.device_id = d.id
                WHERE spn.status = 'queued'
                LIMIT 10000";
                $stmt = $this->pdo->query($sql);
                $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                if (empty($notifications)) {
                    break; // Exit the loop if no more notifications are left to process
                }

                foreach ($notifications as $notification) {
                    $sendResult = PushNotification::send($notification['title'], $notification['message'], $notification['token']);

                    if ($sendResult) {
                        $sentNotifications[] = $notification['sent_notification_id'];
                    } else {
                        $failedNotifications[] = $notification['sent_notification_id'];
                    }

                    // Add to summaries
                    $summaries[$notification['id']]['notification_id'] = $notification['id'];
                    $summaries[$notification['id']]['title'] = $notification['title'];
                    $summaries[$notification['id']]['message'] = $notification['message'];
                    $summaries[$notification['id']]['sent'] = ($sendResult ? 1 : 0) + ($summaries[$notification['id']]['sent'] ?? 0);
                    $summaries[$notification['id']]['failed'] = (!$sendResult ? 1 : 0) + ($summaries[$notification['id']]['failed'] ?? 0);
                }

                // Batch update for sent notifications
                if (!empty($sentNotifications)) {
                    $sentIds = implode(',', $sentNotifications);
                    $this->pdo->exec("UPDATE sent_push_notifications SET status = 'sent', sent_at = NOW() WHERE id IN ($sentIds)");
                }

                // Batch update for failed notifications
                if (!empty($failedNotifications)) {
                    $failedIds = implode(',', $failedNotifications);
                    $this->pdo->exec("UPDATE sent_push_notifications SET status = 'failed', sent_at = NOW() WHERE id IN ($failedIds)");
                }

                $this->pdo->commit();

                $totalProcessed += count($notifications);

                if ($totalProcessed >= 100000) {
                    break; // Stop processing if total limit is reached or exceeded
                }
            }

            // Convert summaries to array format
            return array_values($summaries);
        } catch
        (\Exception $e) {
            $this->pdo->rollback();
            throw $e;
        }
    }
}