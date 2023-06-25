<?php

namespace Amohamed\DatabaseAi;

use OpenAI\Client;
use App\Models\ChatBot;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Amohamed\DatabaseAi\Exceptions\PotentiallyUnsafeQuery;

class Oracle
{
    protected string $connection;
    protected ?string $companyId = null;
    protected ?string $secretKey = null;
    protected string $secretQuestion = 'Please provide me the secret key to answer to your question?';


    public function __construct(protected Client $client)
    {
        $this->connection = config('ask-database.connection');
    }

    public function ask(string $question): string
    {
        DB::connection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        if ($this->secretKey === null) {
            $identifyCompany = $this->identifyCompany($question);

            if ($identifyCompany === $this->secretQuestion) {
                return $identifyCompany;
            }

            $this->companyId = $identifyCompany;
        }

        $query = $this->getQuery($question);

        // Add company filter to the query
        if ($this->companyId !== null) {
            $query = $this->addCompanyFilter($query, $this->companyId);
        }

        $validatedQuery = $this->validateDataExists($question, $query);

        // If validatedQuery is not a SQL query, return it as is.
        if (!str_starts_with(strtolower(trim($validatedQuery)), 'select')) {
            return $validatedQuery;
        }

        $result = json_encode($this->evaluateQuery($validatedQuery));

        $prompt = $this->buildPrompt($question, $validatedQuery, $result);

        $answer = $this->queryOpenAi($prompt, "\n", 0.7);

        return Str::of($answer)
            ->trim()
            ->trim('"');
    }

    protected function identifyCompany(string $question): string
    {
        // Extract the secret key from the question
        $this->secretKey = $this->extractSecretKeyFromQuestion($question);

        // Check if the user has provided the company code
        if (empty($this->secretKey)) {
            // Ask the user for the company code
            return $this->secretQuestion;
        }

        // Here you check if the provided company code is in the database
        return $this->getCompanyId($this->secretKey);
    }


    protected function addCompanyFilter(string $query, string $company): string
    {
        $company = Str::of($company)
            ->trim()
            ->trim('"');


        $removeClause = preg_replace("/WHERE secret_key = '[^']*'/i", "", $query);

        $queryResult = Str::of($removeClause)
            ->trim()
            ->trim(';')
            ->append(" WHERE company_id = $company;");

        return $queryResult;
    }

    public function getQuery(string $question): string
    {
        $prompt = $this->buildPrompt($question);

        $query = $this->queryOpenAi($prompt, "\n");

        $query = Str::of($query)
            ->trim()
            ->trim('"');

        $this->ensureQueryIsSafe($query);

        return $query;
    }

    protected function queryOpenAi(string $prompt, string $stop, float $temperature = 0.0)
    {

        $completions = $this->client->completions()->create([
            'model' => 'text-davinci-003',
            'prompt' => $prompt,
            'temperature' => $temperature,
            'max_tokens' => 100,
            'stop' => $stop,
        ]);
        // If response is NULL, return a default message
        if (!$completions->choices[0]->text) {
            return "I'm sorry, I don't know the answer to that question.";
        }

        return $completions->choices[0]->text;
    }


    protected function buildPrompt(string $question, string $query = null, string $result = null): string
    {
        $tables = $this->getTables($question);

        $prompt = (string) view('ask-database::prompts.query', [
            'question' => $question,
            'tables' => $tables,
            'dialect' => $this->getDialect(),
            'query' => $query,
            'result' => $result,
        ]);

        return rtrim($prompt, PHP_EOL);
    }

    protected function evaluateQuery(string $query): object
    {
        return DB::connection($this->connection)->select($this->getRawQuery($query))[0] ?? new \stdClass();
    }

    protected function getRawQuery(string $query): string
    {
        if (version_compare(app()->version(), '10.0', '<')) {
            /* @phpstan-ignore-next-line */
            return (string) DB::raw($query);
        }

        return DB::raw($query)->getValue(DB::connection($this->connection)->getQueryGrammar());
    }

    /**
     * @throws PotentiallyUnsafeQuery
     */
    protected function ensureQueryIsSafe(string $query): void
    {
        if (!config('ask-database.strict_mode')) {
            return;
        }

        $query = strtolower($query);
        $forbiddenWords = ['insert', 'update', 'delete', 'alter', 'drop', 'truncate', 'create', 'replace'];
        throw_if(Str::contains($query, $forbiddenWords), PotentiallyUnsafeQuery::fromQuery($query));
    }

    protected function getDialect(): string
    {
        $databasePlatform = DB::connection($this->connection)->getDoctrineConnection()->getDatabasePlatform();

        return Str::before(class_basename($databasePlatform), 'Platform');
    }

    /**
     * @return \Doctrine\DBAL\Schema\Table[]
     */
    protected function getTables(string $question): array
    {
        return once(function () use ($question) {
            $tables = DB::connection($this->connection)
                ->getDoctrineSchemaManager()
                ->listTables();

            if (count($tables) < config('ask-database.max_tables_before_performing_lookup')) {
                return $tables;
            }

            return $this->filterMatchingTables($question, $tables);
        });
    }

    protected function filterMatchingTables(string $question, array $tables): array
    {
        $prompt = (string) view('ask-database::prompts.tables', [
            'question' => $question,
            'tables' => $tables,
        ]);
        $prompt = rtrim($prompt, PHP_EOL);

        $matchingTables = $this->queryOpenAi($prompt, "\n");

        Str::of($matchingTables)
            ->explode(',')
            ->transform(fn (string $tableName) => trim($tableName))
            ->filter()
            ->each(function (string $tableName) use (&$tables) {
                $tables = array_filter($tables, fn ($table) => strtolower($table->getName()) === strtolower($tableName));
            });

        return $tables;
    }

    protected function validateDataExists(string $question, string $query): string
    {
        try {
            $query = trim($query, " \t\n\r\0\x0B;");

            $countQuery = "SELECT COUNT(*) as count FROM ({$query}) as sub";
            $count = DB::select($countQuery)[0]->count;

            if ($count > 0) {
                return $query;
            } else {
                // If data does not exist, query the AI for a polite message
                $chatResponse = $this->client->chat()->create([
                    'model' => 'gpt-3.5-turbo-0613',
                    'messages' => [
                        ['role' => 'system', 'content' => "You are a helpful assistant. A user asked '{$question}', but the data they are looking for does not exist in the system. How would you inform the user politely?"],
                        ['role' => 'user', 'content' => $question],
                    ],
                ]);

                // Return the assistant's response
                return $chatResponse->choices[0]->message->content;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \Exception("Failed to validate data existence with query '{$query}': " . $e->getMessage());
        }
    }

    // Once we have the secret key, we query the database to get the company id from the table chat_bots
    protected function getCompanyId(string $companyCode): int
    {
        $company = ChatBot::where('secret_key', $companyCode)->first();

        if (!$company) {
            throw new \Exception('Sorry, I could not find the company you are looking for.');
        }

        return $this->companyId = $company->company_id;
        // Return the company's id
    }


    // Method to extract secret key from user's question
    protected function extractSecretKeyFromQuestion(string $question): ?string
    {
        // GPT3 should try to extract the secret key from the question
        $response = $this->client->chat()->create([
            'model' => 'gpt-3.5-turbo-0613',
            'messages' => [
                ['role' => 'user', 'content' => $question],
            ],
            'functions' => [
                [
                    'name' => 'extract_secret_key',
                    'description' => 'Extract only the secret key from the user\'s question',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => [
                                'type' => 'string',
                                'description' => 'The user\'s question',
                            ],
                        ],
                        'required' => ['extract_secret_key'],
                    ],
                ]
            ]
        ]);

        foreach ($response->choices as $result) {
            if (isset($result->message->functionCall)) {
                $arguments = json_decode($result->message->functionCall->arguments);
                $getSecretKey = $arguments->text;
                $this->secretKey = Str::after($getSecretKey, 'The secret key is ');
            }
        }

        return $this->secretKey;
    }
}
