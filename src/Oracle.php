<?php

namespace Amohamed\DatabaseAi;

use OpenAI\Client;
use App\Models\ChatBot;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Amohamed\DatabaseAi\Exceptions\PotentiallyUnsafeQuery;
use App\Models\Company;

class Oracle
{
    protected string $connection;
    protected ?int $companyId = null;
    protected ?int $userId = null;

    public function __construct(protected Client $client)
    {
        $this->connection = config('ask-database.connection');
    }

    public function setCompanyId(int $companyId)
    {
        $this->companyId = $companyId;

        $company = DB::table('companies')->where('id', $companyId)->first();
        $this->userId = $company->user_id;

        Log::info("Company ID set to {$companyId}");
        Log::info("User ID set to {$this->userId}");
    }

    public function ask(string $question): string
    {
        try {
            DB::connection()->getDoctrineSchemaManager()->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

            $query = $this->getQuery($question);

            $result = json_encode($this->evaluateQuery($query));

            $prompt = $this->buildPrompt($question, $query, $result);

            $answer = $this->queryOpenAi($prompt, "\n", 0.7);

            return Str::of($answer)
                ->trim()
                ->trim('"');
        } catch (\Exception $e) {
            return "Sorry, I don't understand your question.";
        }
    }

    public function getQuery(string $question): string
    {
        $prompt = $this->buildPrompt($question);

        $query = $this->queryOpenAi($prompt, "\n");
        $query = Str::of($query)
            ->trim()
            ->trim('"');

        if ($this->userId !== null) {
            $query = str_replace('<user_id>', $this->userId, $query);
        }

        $this->ensureQueryIsSafe($query);

        return $query;
    }

    protected function queryOpenAi(string $prompt, string $stop, float $temperature = 0.0)
    {
        $completions = $this->client->completions()->create([
            'model' => 'text-davinci-002',
            'prompt' => $prompt,
            'temperature' => $temperature,
            'max_tokens' => 100,
            'stop' => $stop,
        ]);

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
        if ($this->userId !== null) {
            $query = str_replace('<user_id>', '?', $query);
        }

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

            if ($this->companyId !== null) {
                $tables = array_filter($tables, function ($table) {
                    Log::info($table->getCompany()->getId());
                    return $table->getCompany()->getId() === $this->companyId;
                });
            }

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

        $matchingTablesResult = $this->queryOpenAi($prompt, "\n");

        $matchingTables = Str::of($matchingTablesResult)
            ->explode(',')
            ->transform(fn (string $tableName) => strtolower(trim($tableName)));

        return collect($tables)->filter(function ($table) use ($matchingTables) {
            return $matchingTables->contains(strtolower($table->getName()));
        })->toArray();
    }

    public function authenticateWithSecretKey(string $secretKey): bool
    {
        $chatBot = ChatBot::where('secret_key', $secretKey)->first();

        if ($chatBot) {
            $this->setCompanyId($chatBot->company_id);
            return true;
        }

        return false;
    }
}
