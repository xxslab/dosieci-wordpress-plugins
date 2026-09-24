<?php

declare(strict_types=1);

namespace DoSieci\AiOperator\Domain\Gateway;

use DoSieci\AiOperator\Domain\HubException;
use DoSieci\AiOperator\Domain\TransportException;

/**
 * One turn of a conversation with a model, however that model is reached.
 *
 * This interface is what lets ChatSession stay identical whether the site
 * routes through the DoSieci Hub (hosted credits, provider key never
 * leaves the Hub) or calls Anthropic/OpenAI directly with the site owner's
 * own key (BYOK). The tool-execution loop, the local capability gates and
 * the audit trail are the same in both modes -- only who answers the HTTP
 * call changes.
 *
 * Implementations MUST return the Hub wire format, because that is the
 * shape ChatSession already understands and the shape the Hub itself
 * speaks:
 *
 *   {type: 'final_answer',      answer: string}
 *   {type: 'tool_call',         tool_name, tool_use_id, arguments}
 *   {type: 'tool_call_denied',  tool_name, tool_use_id, reason}
 *
 * A direct-provider implementation is therefore responsible for
 * translating its provider's native response into this shape -- it does
 * NOT get to invent a fourth type.
 */
interface ChatGatewayInterface {

	/**
	 * @param array<int, array<string, mixed>> $conversation conversation so far, in Hub wire format
	 *
	 * @return array<string, mixed>
	 *
	 * @throws HubException|TransportException
	 */
	public function chat( string $requestId, array $conversation ): array;

	/**
	 * Human-readable label for the admin UI ("DoSieci Hub", "Anthropic
	 * (własny klucz)"), so the chat screen can state plainly where the
	 * answer came from and whose credits paid for it.
	 */
	public function label(): string;
}
