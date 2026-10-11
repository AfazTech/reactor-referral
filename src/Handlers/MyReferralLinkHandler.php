<?php

namespace AfazTech\ReactorReferral\Handlers;

use App\Contracts\Repository\UserRepositoryInterface;
use App\Handlers\BaseHandler;
use AfazTech\ReactorReferral\Contracts\ReferralManagerInterface;
use Neili\Client;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Text;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Handles the "/myinvite" and "/ref" commands.
 *
 * Sends the caller their personal invite link together with a summary
 * of their referral statistics and an inline button that opens
 * Telegram's native share sheet.
 *
 * Every string is resolved through the language manager, so the reply
 * is automatically localised. The host application provides the
 * translations for the following keys:
 *
 *   - referral_link_message : {link}, {confirmed}, {pending}, {total}
 *   - referral_share_button : label of the share button
 *   - referral_share_text   : body of the shared message
 */
#[Text(name: '/myinvite', isCommand: true, priority: 80)]
#[Text(name: '/ref', isCommand: true, priority: 80)]
#[OnUpdate('message')]
class MyReferralLinkHandler extends BaseHandler
{
    public function __construct(
        Client $client,
        LanguageInterface $language,
        LoggerInterface $logger,
        UserRepositoryInterface $userRepository,
        private ReferralManagerInterface $referralManager,
    ) {
        parent::__construct($client, $language, $logger, $userRepository);
    }

    protected function handle(array $params = []): void
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return;
        }

        $lang  = $this->getUserLanguage();
        $link  = $this->referralManager->getLink($userId);
        $stats = $this->referralManager->stats($userId);

        $text = $this->language->get('referral_link_message', $lang, [
            'link'      => $link,
            'confirmed' => (string) $stats['confirmed'],
            'pending'   => (string) $stats['pending'],
            'total'     => (string) $stats['total'],
        ]);

        $shareText = $this->language->get('referral_share_text', $lang);
        $shareUrl  = 'https://t.me/share/url'
            . '?url=' . urlencode($link)
            . '&text=' . urlencode($shareText);

        $keyboard = [
            'inline_keyboard' => [
                [[
                    'text' => $this->language->get('referral_share_button', $lang),
                    'url'  => $shareUrl,
                ]],
            ],
        ];

        $this->reply($text, $keyboard);
    }
}
