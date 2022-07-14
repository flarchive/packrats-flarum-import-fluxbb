<?php

namespace Packrats\ImportFluxBB\Importer;

use DateTime;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Users
{
    /**
     * @var ConnectionInterface
     */
    private $database;
    /**
     * @var PDO
     */
    private $fluxBBDatabase;
    /**
     * @var string
     */
    private $fluxBBPrefix;

    public function __construct(ConnectionInterface $database)
    {
        $this->database = $database;
    }

    public function execute(OutputInterface $output, PDO $fluxBBDatabase, string $fluxBBPrefix)
    {
        $this->fluxBBDatabase = $fluxBBDatabase;
        $this->fluxBBPrefix = $fluxBBPrefix;
        $output->writeln('Importing users...');

        $fields = [
            'id',
            'group_id',
            'username',
            'password',
            'email',
            'title',
            'realname',
            'url',
            'jabber',
            'location',
            'signature',
            'disp_topics',
            'disp_posts',
            'email_setting',
            'notify_with_post',
            'auto_notify',
            'show_smilies',
            'show_img',
            'show_img_sig',
            'show_avatars',
            'show_sig',
            'timezone',
            'dst',
            'time_format',
            'date_format',
            'language',
            'style',
            'num_posts',
            'last_post',
            'last_search',
            'last_email_sent',
            'last_report_sent',
            'registered',
            'registration_ip',
            'last_visit',
            'admin_note',
            'activate_string',
            'activate_key'
        ];

        $sql = sprintf(
            "SELECT %s FROM %s WHERE `username` != 'Guest' ORDER BY `id`",
            implode(', ', $fields),
            $this->fluxBBPrefix .'users'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $users = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($users));

        $userNames = $this->createUsernameMap($users);

        foreach ($users as $user) {
            $lastSeenAt = (new DateTime())->setTimestamp($user->last_visit);

            if ((int)$user->id === 2) { // Assuming that the first user of flarum is the same admin/user as in the old forum
                $this->database
                    ->table('users')
                    ->where('id', 1)
                    ->update([
                        'joined_at' => (new DateTime())->setTimestamp($user->registered),
                        'last_seen_at' => $lastSeenAt,
                        'discussion_count' => $this->getDiscussionCount($user->id),
                        'comment_count' => $this->getCommentCount($user->id),
                    ]);
            } else {
                $this->database
                    ->table('users')
                    ->insert(
                        [
                            'id' => $user->id,
                            'username' => $userNames[$user->id],
                            'nickname' => $user->username,
                            'email' => $user->email,
                            'is_email_confirmed' => $user->group_id == 0 ? 0 : 1,
                            'password' => '', // password will be migrated by migratetoflarum/old-passwords
                            'preferences' => $this->createPreferences($user),
                            'joined_at' => (new DateTime())->setTimestamp($user->registered),
                            'last_seen_at' => $lastSeenAt,
                            'marked_all_as_read_at' => $lastSeenAt,
                            'read_notifications_at' => null,
                            'discussion_count' => $this->getDiscussionCount($user->id),
                            'comment_count' => $this->getCommentCount($user->id),
                            'read_flags_at' => null,
                            'suspended_until' => null,
                            'migratetoflarum_old_password' => $this->createOldPasswordHash($user->password)
                        ]
                    );
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $output->writeln('');
    }

    private function isValidUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9_-]{3,30}$/i', $username);
    }

    /**
     * See https://github.com/migratetoflarum/old-passwords#sha1-bcrypt
     */
    private function createOldPasswordHash(string $passwordHash): ?string
    {
        $recrypt = true;
        if ($recrypt) {
            $data = [
                'type' => 'sha1-bcrypt',
                'password' => password_hash($passwordHash, PASSWORD_BCRYPT)
            ];
        } else {
            $data = [
                'type' => 'sha1',
                'password' => $passwordHash
            ];
        }

        return json_encode($data) ?? null;
    }

    private function createPreferences($user): ?string
    {
        $preferences = [];

        if ($user->auto_notify) {
            $preferences['followAfterReply'] = true;
        }

        if (!$preferences) {
            return null;
        }

        return json_encode($preferences);
    }

    private function createUsernameMap(array $users): array
    {
        $userNames = [];
        foreach ($users as $user) {
            $userNames[mb_strtolower($user->username)] = ['id' => $user->id, 'username' => $user->username];
        }

        foreach ($userNames as $userNameKey => $userData) {
            $userName = $userData['username'];

            if (!$this->isValidUsername($userName)) {
                $newUserName = preg_replace('/\.+/', '-', $userName);
                $newUserName = trim($newUserName, '-');
                $newUserName = Str::slug($newUserName, '-', 'de');

                if (strlen($newUserName) < 3 || isset($userNames[mb_strtolower($newUserName)])) {
                    $newUserName = ($newUserName ? $newUserName : 'user') . '-' . $userData['id'];
                }

                if (!$this->isValidUsername($newUserName)) {
                    throw new \RuntimeException('Username still invalid: ' . $newUserName);
                }

                unset($userNames[$userNameKey]);
                $userNames[mb_strtolower($newUserName)] = ['id' => $userData['id'], 'username' => $newUserName];
            }
        }

        $userNamesMap = [];
        foreach ($userNames as $userData) {
            $userNamesMap[$userData['id']] = $userData['username'];
        }

        return $userNamesMap;
    }

    private function getDiscussionCount(int $userId): int
    {
        $sql = sprintf(
            "SELECT COUNT(`topic_id`) FROM %s JOIN %s ON %s = %s WHERE %s = :userId",
            $this->fluxBBPrefix .'topics',
            $this->fluxBBPrefix .'posts',
            $this->fluxBBPrefix .'topics.first_post_id',
            $this->fluxBBPrefix .'posts.id',
            $this->fluxBBPrefix .'posts.poster_id'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function getCommentCount(int $userId): int
    {
        $sql = sprintf(
            "SELECT COUNT(`id`) FROM %s WHERE `poster_id = :userId`",
            $this->fluxBBPrefix .'posts'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }
}
