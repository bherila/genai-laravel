<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Forced tool choice on models that reject `tool_choice` `any` / `tool`.
 */
class AnthropicUnforcedToolChoiceTest extends TestCase
{
    private const REJECTION = '{"type":"error","error":{"type":"invalid_request_error","message":"tool_choice: type \"tool\" and \"any\" are not supported for this model."}}';

    protected function setUp(): void
    {
        parent::setUp();
        AnthropicClient::forgetLearnedToolChoiceRejections();
    }

    protected function tearDown(): void
    {
        AnthropicClient::forgetLearnedToolChoiceRejections();
        parent::tearDown();
    }

    public function test_a_model_that_accepts_forcing_still_gets_the_forced_choice(): void
    {
        Http::fake(['*' => Http::response($this->toolUseResponse())]);

        $this->client('claude-sonnet-4-6')->converse('Be precise.', $this->messages(), $this->config(ToolChoice::any()));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req) => $req->data()['tool_choice'] === ['type' => 'any']
            && $req->data()['system'] === [['type' => 'text', 'text' => 'Be precise.']]);
    }

    public function test_any_on_a_rejecting_model_is_sent_as_auto_with_an_instruction(): void
    {
        Http::fake(['*' => Http::response($this->toolUseResponse())]);

        $response = $this->client('claude-sonnet-5-5')->converse('', $this->messages(), $this->config(ToolChoice::any()));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req) => $req->data()['tool_choice'] === ['type' => 'auto']
            && $req->data()['system'][0]['text'] === 'Answer by calling one of the provided tools. Do not answer in plain text.');
        $this->assertSame('extract', $response['content'][0]['name']);
    }

    public function test_a_named_tool_is_named_in_the_instruction_after_the_callers_system_prompt(): void
    {
        Http::fake(['*' => Http::response($this->toolUseResponse())]);

        $this->client('claude-opus-5-5')->converse('Be precise.', $this->messages(), $this->config(ToolChoice::tool('extract')));

        Http::assertSent(fn (Request $req) => $req->data()['tool_choice'] === ['type' => 'auto']
            && $req->data()['system'][0]['text'] === "Be precise.\n\nAnswer by calling the `extract` tool. Do not answer in plain text.");
    }

    public function test_dated_ids_of_a_rejecting_model_match(): void
    {
        $this->assertTrue($this->client('claude-sonnet-5-5-20261101')->rejectsForcedToolChoice());
        $this->assertTrue($this->client('claude-fable-5-2')->rejectsForcedToolChoice());
        $this->assertFalse($this->client('claude-sonnet-5')->rejectsForcedToolChoice());
        $this->assertFalse($this->client('claude-opus-5')->rejectsForcedToolChoice());
        $this->assertFalse($this->client('claude-haiku-5-5')->rejectsForcedToolChoice());
    }

    public function test_auto_choice_is_sent_unchanged_on_a_rejecting_model(): void
    {
        Http::fake(['*' => Http::response($this->textResponse('plain'))]);

        $this->client('claude-sonnet-5-5')->converse('', $this->messages(), $this->config(ToolChoice::auto()));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req) => $req->data()['tool_choice'] === ['type' => 'auto']
            && ! array_key_exists('system', $req->data()));
    }

    public function test_a_plain_text_reply_is_asked_once_more_in_the_same_conversation(): void
    {
        $thinking = ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-abc'];
        Http::fakeSequence()
            ->push($this->textResponse('The total is 12.', [$thinking], ['input_tokens' => 100, 'output_tokens' => 20, 'cache_read_input_tokens' => 7]))
            ->push($this->toolUseResponse(['input_tokens' => 130, 'output_tokens' => 15]));

        $response = $this->client('claude-sonnet-5-5')->converse('', $this->messages(), $this->config(ToolChoice::tool('extract')));

        Http::assertSentCount(2);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $retry = $requests[1]['messages'];
        $this->assertCount(3, $retry);
        $this->assertSame('assistant', $retry[1]['role']);
        $this->assertSame($thinking, $retry[1]['content'][0], 'the thinking block is echoed with its signature');
        $this->assertSame('The total is 12.', $retry[1]['content'][1]['text']);
        $this->assertSame([['type' => 'text', 'text' => 'Call the `extract` tool now with your answer.']], $retry[2]['content']);
        $this->assertSame(['type' => 'auto'], $requests[1]['tool_choice']);

        $this->assertSame('tool_use', $response['content'][0]['type']);
        $this->assertSame(230, $response['usage']['input_tokens']);
        $this->assertSame(35, $response['usage']['output_tokens']);
        $this->assertSame(7, $response['usage']['cache_read_input_tokens']);
    }

    public function test_a_second_plain_text_reply_is_returned_without_a_third_request(): void
    {
        Http::fakeSequence()
            ->push($this->textResponse('No.'))
            ->push($this->textResponse('Still no.'));

        $response = $this->client('claude-sonnet-5-5')->converse('', $this->messages(), $this->config(ToolChoice::any()));

        Http::assertSentCount(2);
        $this->assertSame('Still no.', $response['content'][0]['text']);
    }

    public function test_a_reply_cut_off_by_max_tokens_is_not_asked_again(): void
    {
        Http::fake(['*' => Http::response(array_merge($this->textResponse('partial'), ['stop_reason' => 'max_tokens']))]);

        $this->client('claude-sonnet-5-5')->converse('', $this->messages(), $this->config(ToolChoice::any()));

        Http::assertSentCount(1);
    }

    public function test_an_unlisted_model_is_learned_from_its_rejection(): void
    {
        Http::fakeSequence()
            ->push(self::REJECTION, 400)
            ->push($this->toolUseResponse())
            ->push($this->toolUseResponse());

        $this->client('claude-future-7')->converse('', $this->messages(), $this->config(ToolChoice::any()));
        $this->client('claude-future-7')->converse('', $this->messages(), $this->config(ToolChoice::any()));

        $choices = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['tool_choice'])->values()->all();
        $this->assertSame([['type' => 'any'], ['type' => 'auto'], ['type' => 'auto']], $choices);
    }

    public function test_any_other_400_is_thrown_and_not_retried(): void
    {
        Http::fake(['*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'prompt is too long']], 400)]);

        try {
            $this->client('claude-future-7')->converse('', $this->messages(), $this->config(ToolChoice::any()));
            $this->fail('expected the 400 to be thrown');
        } catch (GenAiException $e) {
            $this->assertStringContainsString('prompt is too long', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertFalse($this->client('claude-future-7')->rejectsForcedToolChoice());
    }

    private function client(string $model): AnthropicClient
    {
        return new AnthropicClient(apiKey: 'test-key', model: $model);
    }

    /**
     * @return list<array{role: string, content: list<ContentBlock>}>
     */
    private function messages(): array
    {
        return [['role' => 'user', 'content' => [ContentBlock::text('Extract the total.')]]];
    }

    private function config(ToolChoice $choice): ToolConfig
    {
        return new ToolConfig(
            tools: [new ToolDefinition('extract', 'Extract data', Schema::object([
                'amount' => Schema::number('Dollar amount'),
            ], required: ['amount']))],
            choice: $choice,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $leading
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    private function textResponse(string $text, array $leading = [], array $usage = ['input_tokens' => 10, 'output_tokens' => 5]): array
    {
        return [
            'id' => 'msg_text',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [...$leading, ['type' => 'text', 'text' => $text]],
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'end_turn',
            'usage' => $usage,
        ];
    }

    /**
     * @param  array<string, int>  $usage
     * @return array<string, mixed>
     */
    private function toolUseResponse(array $usage = ['input_tokens' => 10, 'output_tokens' => 5]): array
    {
        return [
            'id' => 'msg_tool',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'extract', 'input' => ['amount' => 12]]],
            'model' => 'claude-sonnet-5-5',
            'stop_reason' => 'tool_use',
            'usage' => $usage,
        ];
    }
}
