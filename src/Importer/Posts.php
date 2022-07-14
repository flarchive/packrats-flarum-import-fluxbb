<?php

namespace Packrats\ImportFluxBB\Importer;

use DateTime;
use Flarum\Formatter\Formatter;
use Flarum\Foundation\ContainerUtil;
use Flarum\Foundation\Paths;
use Flarum\Mentions\ConfigureMentions;
use Flarum\Post\CommentPost;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\Filesystem;
use PDO;
use s9e\TextFormatter\Configurator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Posts
{
    /**
     * @var ConnectionInterface
     */
    private $database;
    /**
     * @var Formatter
     */
    private $importFormatter;
    /**
     * @var Container
     */
    protected $container;
    /**
     * @var PDO
     */
    private $fluxBBDatabase;
    /**
     * @var string
     */
    private $fluxBBPrefix;

    public function __construct(ConnectionInterface $database, Container $container)
    {
        $this->database = $database;
        $this->container = $container;
    }

    public function execute(OutputInterface $output, PDO $fluxBBDatabase, string $fluxBBPrefix)
    {
        $this->fluxBBDatabase = $fluxBBDatabase;
        $this->fluxBBPrefix = $fluxBBPrefix;
        $this->importFormatter = $this->createFormater();

        $output->writeln('Importing posts...');

        $fields = [
            'id',
            'poster',
            'poster_id',
            'poster_ip',
            'poster_email',
            'message',
            'hide_smilies',
            'posted',
            'edited',
            'edited_by',
            'topic_id'
        ];
        $sql = sprintf(
            "SELECT %s FROM %s ORDER BY `topic_id`, `id`",
            implode(', ', $fields),
            $this->fluxBBPrefix .'posts'
        );
        $stmt = $this->fluxBBDatabase->query($sql);
        $posts = $stmt->fetchAll(PDO::FETCH_OBJ);

        $progressBar = new ProgressBar($output, count($posts));

        $this->database->statement('SET FOREIGN_KEY_CHECKS=0');
        $lastTopicId = 0;
        $currentPostNumber = 0;
        foreach ($posts as $post) {
            if ($lastTopicId !== $post->topic_id) {
                $currentPostNumber = 0;
            }
            $currentPostNumber++;
            $this->database
                ->table('posts')
                ->insert(
                    [
                        'id' => $post->id,
                        'discussion_id' => $post->topic_id,
                        'number' => $currentPostNumber,
                        'created_at' => (new DateTime())->setTimestamp($post->posted),
                        'user_id' => $post->poster_id > 1 ? $post->poster_id : null,
                        'type' => 'comment',
                        'content' => $this->convertPostContent($post),
                        'edited_at' => $post->edited ? (new DateTime())->setTimestamp($post->edited) : null,
                        'edited_user_id' => $post->edited_by ? $this->getUserByName($post->edited_by) : null,
                        'hidden_at' => null,
                        'hidden_user_id' => null,
                        'ip_address' => $post->poster_ip,
                        'is_private' => 0,
                        'is_approved' => 1
                    ]
                );
            $lastTopicId = $post->topic_id;
            $progressBar->advance();
        }
        $this->database->statement('SET FOREIGN_KEY_CHECKS=1');
        $progressBar->finish();

        $output->writeln('');
    }

    private function getUserByName(string $nickname): ?int
    {
        $sql = sprintf(
            "SELECT `id` FROM %s WHERE `username` = :nickname",
            $this->fluxBBPrefix .'users'
        );
        $stmt = $this->fluxBBDatabase->prepare($sql);
        $stmt->bindValue('nickname', $nickname);
        $stmt->execute();
        $userId = $stmt->fetchColumn();

        return $userId ? (int)$userId : null;
    }

    private function convertPostContent(object $post): string
    {
        $content = $this->replaceUnsupportedBBCode($post->message);
        return $this->importFormatter->parse(
            $content,
            CommentPost::reply($post->topic_id, $content, $post->poster_id, $post->poster_ip)
        );
    }

    private function replaceUnsupportedBBCode(string $text): string
    {
        $replacements = [
            '#\[h\](.+?)\[/h\]#i' => '[B]$1[/B]',
            '#\[em\](.+?)\[/em\]#i' => '[I]$1[/I]',
            '#\[ins\](.+?)\[/ins\]#i' => '[I]$1[/I]',

            // FluxBB uses a different syntax
            '#\[img=(.+?)\](.+?)\[/img\]#i' => '[IMG ALT=$1]$2[/IMG]',

            '#<a href="\[url\](.+?)\[/url\]"?[^>]*>.+?</a>#s' => '[URL]$1[/URL]',

            '#<a href="?id=20;page=GetAttachment;file=1485" rel="nofollow"><img src="?id=20;page=GetAttachmentThumb;file=1485" alt="screenshot2.png" title="screenshot2.png" class="image" /></a>#' => '[URL]$1[/URL]',

            '#<a href="(.+?)">\[url\].+?\[/url\](\.\.\.)?</a>#' => '[URL]$1[/URL]',

            '#<a href="([^"]+)">(.+?)</a>#' => '[URL=$1]$2[/URL]',
        ];

        return preg_replace(array_keys($replacements), array_values($replacements), $text);
    }

    protected function createFormater(): Formatter
    {
        $cacheDirectory = $this->container[Paths::class]->storage . '/tmp/import-formatter';
        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory);
        }
        $formatter = new Formatter(new Repository(new FileStore(new Filesystem, $cacheDirectory)), $cacheDirectory);

        $formatter->addConfigurationCallback(
            function (Configurator $config) {
                // BBCode extension
                $config->BBCodes->addFromRepository('B');
                $config->BBCodes->addFromRepository('I');
                $config->BBCodes->addFromRepository('U');
                $config->BBCodes->addFromRepository('S');
                $config->BBCodes->addFromRepository('URL');
                $config->BBCodes->addFromRepository('IMG');
                $config->BBCodes->addFromRepository('EMAIL');
                $config->BBCodes->addFromRepository('CODE');
                $config->BBCodes->addFromRepository('QUOTE');
                $config->BBCodes->addFromRepository('LIST');
                $config->BBCodes->addFromRepository('DEL');
                $config->BBCodes->addFromRepository('COLOR');
//                $config->BBCodes->addFromRepository('CENTER');
//                $config->BBCodes->addFromRepository('SIZE');
                $config->BBCodes->addFromRepository('*');

                // Emoji extension
                $config->Emoticons->add(':)', '🙂');
                $config->Emoticons->add(':D', '😃');
                $config->Emoticons->add(':P', '😛');
                $config->Emoticons->add(':(', '🙁');
                $config->Emoticons->add(':|', '😐');
                $config->Emoticons->add(';)', '😉');
                $config->Emoticons->add(':\'(', '😢');
                $config->Emoticons->add(':O', '😮');
                $config->Emoticons->add('>:(', '😡');

                // Reduce false positive in e.g. URLs and :/
                $config->Emoticons->notAfter = '\b';

                // Emoticons in addition to https://github.com/flarum/emoji/blob/master/extend.php#L20
                $emoticons = [
                    // Old LL
                    // See https://github.com/pierres/ll/blob/origin/modules/Markup.php#L44
                    ';D' => '😁',
                    '::)' => '🙄',
                    ':-\\' => '😕',
                    ':-X' => '🤐',
                    ':-x' => '🤐',
                    ':-[' => '😅',
                    '8)' => '😎',
                    '???' => '😕',
                    ':\'(' => '😢',
                    '\'(' => '😢',

                    // LL
                    // See https://github.com/pierres/ll/blob/master/modules/Markup.php#L38
                    '0:-)' => '😇',
                    ':-*)' => '😳',
                    ':-*' => '😘',
                    'xD' => '😆',
                    ':-|' => '😐',
                    ':-P' => '😛',
                    ':-(' => '🙁',
                    ':-D' => '😃',
                    ':-)' => '🙂',
                    ':-0' => '😮',
                    ':-/' => '😕',
                    ';-)' => '😉',

                    // FluxBB
                    // See https://github.com/fluxbb/fluxbb/blob/master/include/parser.php#L42
                    '=)' => '🙂',
                    '=|' => '😐',
                    '=(' => '🙁',
                    '=D' => '😃',
                    ':o' => '😮',
                    ':/' => '😕',
                    ':p' => '😛',
                    ':lol:' => '😂',
                    ':mad:' => '😡',
                    ':rolleyes:' => '🙄',
                    ':cool:' => '😎',
                ];

                foreach ($emoticons as $code => $emoji) {
                    $config->Emoticons->add($code, $emoji);
                }

                $config->urlConfig->allowScheme('ftp');
            }
        );

        $formatter->addConfigurationCallback(ContainerUtil::wrapCallback(ConfigureMentions::class, $this->container));

        return $formatter;
    }
}
