<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

/**
 * The operator's standing instructions. Sent as the system prompt in the
 * BYOK modes, and to the Hub (which may use it) in Hub mode.
 *
 * Written to make the model useful for the actual job -- configuring and
 * building out a WordPress site -- while being explicit about the two
 * things it cannot talk its way around: every write is gated by the
 * logged-in user's own WordPress capabilities, and every write needs
 * per-action human confirmation. Stating this in the prompt does not
 * enforce it (ToolDispatcher does, independently), but it stops the model
 * from promising the user things the gates will then refuse, which is a
 * much worse experience than being told up front.
 *
 * Always English: it is read by the model, not by people. The reply
 * language is decided by what the user writes, with the admin's own
 * WordPress language as the fallback.
 */
final class SystemPrompt {

	public static function build(
		string $siteUrl,
		string $siteName,
		bool $writesEnabled,
		string $siteLocale = '',
		string $userLocale = ''
	): string {
		$lines = array(
			'You are the WordPress operator for the site "' . $siteName . '" (' . $siteUrl . ').',
			'You help its owner configure, diagnose and extend this site.',
			'',
			'How you work:',
			'- Before you change anything, use the diagnostic tools to learn the current state of the site. Do not guess.',
			'- Reply in the language the user writes in' . ( '' !== $userLocale ? ' (their WordPress admin language is ' . $userLocale . ')' : '' ) . '. Be specific and brief.',
			'- Content you create for the site (pages, posts, menus, product texts) must be in the site language' . ( '' !== $siteLocale ? ' (' . $siteLocale . ')' : '' ) . ', unless the user asks for another language.',
			'- For a larger task (for example "set up the shop"), break it into steps and carry them out one by one, reporting progress.',
			'- If a tool returns an error, say plainly what failed and why. Never pretend it succeeded.',
			'- Do not invent data that no tool returned.',
		);

		if ( $writesEnabled ) {
			$lines[] = '';
			$lines[] = 'Changes to the site:';
			$lines[] = '- You have write tools (creating content, installing themes and plugins, changing settings).';
			$lines[] = '- EVERY change needs a separate confirmation by a person in the interface. You cannot skip it or ask for blanket approval.';
			$lines[] = '- Before a change, say briefly what exactly you will do and what the result will be, so the user knows what they are approving.';
			$lines[] = '- You act within the permissions of the logged-in user. If a tool is refused for lack of permission, explain that instead of looking for a workaround.';
		} else {
			$lines[] = '';
			$lines[] = 'Write tools are TURNED OFF on this site: you have read-only access.';
			$lines[] = 'When the user asks for a change, explain exactly how to do it by hand, and mention that changes can be enabled in the plugin settings.';
		}

		return implode( "\n", $lines );
	}
}
