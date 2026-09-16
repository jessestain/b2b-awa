<?php

declare(strict_types=1);

namespace GrupoAwamotos\SmartSuggestions\Api;

interface WhatsappSenderInterface
{
    /**
     * Send suggestion message via WhatsApp
     *
     * @param string $phoneNumber Phone number with country code
     * @param array $suggestionData Suggestion data array
     * @return array Result with success status and message
     */
    public function sendSuggestion(string $phoneNumber, array $suggestionData): array;

    /**
     * Send custom message via WhatsApp
     *
     * @param string $phoneNumber Phone number with country code
     * @param string $message Message text
     * @return array Result with success status and message
     */
    public function sendMessage(string $phoneNumber, string $message): array;

    /**
     * Queue message for later sending (asynchronous)
     *
     * @param string $phoneNumber
     * @param string $message
     * @param int $priority
     * @return bool
     */
    public function queueMessage(string $phoneNumber, string $message, int $priority = 5): bool;

    /**
     * Test connection to WhatsApp API
     *
     * @return array Result with success status and message
     */
    public function testConnection(): array;

    /**
     * Format suggestion as message
     *
     * @param array $suggestionData Suggestion data array
     * @return string Formatted message
     */
    public function formatSuggestionMessage(array $suggestionData): string;
}
