<?php

namespace InfyOm\Generator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use Illuminate\Support\Str;
use InfyOm\Generator\Common\GeneratorConfig;
use InfyOm\Generator\Common\GeneratorField;
use InfyOm\Generator\Common\GeneratorFieldRelation;
use InfyOm\Generator\Events\GeneratorFileCreated;
use InfyOm\Generator\Events\GeneratorFileCreating;
use InfyOm\Generator\Events\GeneratorFileDeleted;
use InfyOm\Generator\Events\GeneratorFileDeleting;
use InfyOm\Generator\Generators\API\APIControllerGenerator;
use InfyOm\Generator\Generators\API\APIRequestGenerator;
use InfyOm\Generator\Generators\API\APIResourceGenerator;
use InfyOm\Generator\Generators\API\APIRoutesGenerator;
use InfyOm\Generator\Generators\API\APITestGenerator;
use InfyOm\Generator\Generators\FactoryGenerator;
use InfyOm\Generator\Generators\MigrationGenerator;
use InfyOm\Generator\Generators\ModelGenerator;
use InfyOm\Generator\Generators\RepositoryGenerator;
use InfyOm\Generator\Generators\RepositoryTestGenerator;
use InfyOm\Generator\Generators\Scaffold\ControllerGenerator;
use InfyOm\Generator\Generators\Scaffold\MenuGenerator;
use InfyOm\Generator\Generators\Scaffold\RequestGenerator;
use InfyOm\Generator\Generators\Scaffold\RoutesGenerator;
use InfyOm\Generator\Generators\Scaffold\ViewGenerator;
use InfyOm\Generator\Generators\SeederGenerator;
use InfyOm\Generator\Utils\GeneratorFieldsInputUtil;
use InfyOm\Generator\Utils\TableFieldsGenerator;
use Mockery\Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\VarExporter\VarExporter;

class BaseCommand extends Command
{
    public GeneratorConfig $config;

    public Composer $composer;

    public function __construct()
    {
        parent::__construct();

        $this->composer = app()['composer'];
    }

    public function handle()
    {
        $this->config = app(GeneratorConfig::class);
        $this->config->setCommand($this);

        $this->config->init();
        $this->getFields();
    }

    public function generateCommonItems()
    {
        if (!$this->option('fromTable') and !$this->isSkip('migration')) {
            $migrationGenerator = app(MigrationGenerator::class);
            $migrationGenerator->generate();
        }

        if (!$this->isSkip('model')) {
            $modelGenerator = app(ModelGenerator::class);
            $modelGenerator->generate();
        }

        if (!$this->isSkip('repository') && $this->config->options->repositoryPattern) {
            $repositoryGenerator = app(RepositoryGenerator::class);
            $repositoryGenerator->generate();
        }

        if ($this->config->options->factory || (!$this->isSkip('tests') and $this->config->options->tests)) {
            $factoryGenerator = app(FactoryGenerator::class);
            $factoryGenerator->generate();
        }

        if ($this->config->options->seeder) {
            $seederGenerator = app(SeederGenerator::class);
            $seederGenerator->generate();
        }
    }

    public function generateAPIItems()
    {
        if (!$this->isSkip('requests') and !$this->isSkip('api_requests')) {
            $requestGenerator = app(APIRequestGenerator::class);
            $requestGenerator->generate();
        }

        if (!$this->isSkip('controllers') and !$this->isSkip('api_controller')) {
            $controllerGenerator = app(APIControllerGenerator::class);
            $controllerGenerator->generate();
        }

        if (!$this->isSkip('routes') and !$this->isSkip('api_routes')) {
            $routesGenerator = app(APIRoutesGenerator::class);
            $routesGenerator->generate();
        }

        if (!$this->isSkip('tests') and $this->config->options->tests) {
            if ($this->config->options->repositoryPattern) {
                $repositoryTestGenerator = app(RepositoryTestGenerator::class);
                $repositoryTestGenerator->generate();
            }

            $apiTestGenerator = app(APITestGenerator::class);
            $apiTestGenerator->generate();
        }

        if ($this->config->options->resources) {
            $apiResourceGenerator = app(APIResourceGenerator::class);
            $apiResourceGenerator->generate();
        }
    }

    public function generateScaffoldItems()
    {
        if (!$this->isSkip('requests') and !$this->isSkip('scaffold_requests')) {
            $requestGenerator = app(RequestGenerator::class);
            $requestGenerator->generate();
        }

        if (!$this->isSkip('controllers') and !$this->isSkip('scaffold_controller')) {
            $controllerGenerator = app(ControllerGenerator::class);
            $controllerGenerator->generate();
        }

        if (!$this->isSkip('views')) {
            $viewGenerator = app(ViewGenerator::class);
            $viewGenerator->generate();
        }

        if (!$this->isSkip('routes') and !$this->isSkip('scaffold_routes')) {
            $routeGenerator = app(RoutesGenerator::class);
            $routeGenerator->generate();
        }

        if (!$this->isSkip('menu')) {
            $menuGenerator = app(MenuGenerator::class);
            $menuGenerator->generate();
        }
    }

    public function performPostActions($runMigration = false)
    {
        if ($this->config->options->saveSchemaFile) {
            $this->saveSchemaFile();
        }

        if ($runMigration) {
            if ($this->option('forceMigrate')) {
                $this->runMigration();
            } elseif (!$this->option('fromTable') and !$this->isSkip('migration')) {
                $requestFromConsole = (php_sapi_name() == 'cli');
                if ($this->option('jsonFromGUI') && $requestFromConsole) {
                    $this->runMigration();
                } elseif ($requestFromConsole && $this->confirm(infy_nl() . 'Do you want to migrate database? [y|N]', false)) {
                    $this->runMigration();
                }
            }
        }

        if ($this->config->options->localized) {
            $this->saveLocaleFile();
        }

        if (!$this->isSkip('dump-autoload')) {
            $this->info('Generating autoload files');
            $this->composer->dumpOptimized();
        }
    }

    public function runMigration(): bool
    {
        $migrationPath = config('laravel_generator.path.migration', database_path('migrations/'));
        $path = Str::after($migrationPath, base_path()); // get path after base_path
        $this->call('migrate', ['--path' => $path, '--force' => true]);

        return true;
    }

    public function isSkip($skip): bool
    {
        if ($this->option('skip')) {
            return in_array($skip, (array)$this->option('skip'));
        }

        return false;
    }

    public function performPostActionsWithMigration()
    {
        $this->performPostActions(true);
    }

    protected function saveSchemaFile()
    {
        $fileFields = [];

        foreach ($this->config->fields as $field) {
            $fileFields[] = [
                'name' => $field->name,
                'dbType' => $field->dbType,
                'htmlType' => $field->htmlType,
                'validations' => $field->validations,
                'searchable' => $field->isSearchable,
                'fillable' => $field->isFillable,
                'primary' => $field->isPrimary,
                'inForm' => $field->inForm,
                'inIndex' => $field->inIndex,
                'inView' => $field->inView,
            ];
        }

        foreach ($this->config->relations as $relation) {
            $fileFields[] = [
                'type' => 'relation',
                'relation' => $relation->type . ',' . implode(',', $relation->inputs),
            ];
        }

        $path = config('laravel_generator.path.schema_files', resource_path('model_schemas/'));

        $fileName = $this->config->modelNames->name . '.json';

        if (file_exists($path . $fileName) && !$this->confirmOverwrite($fileName)) {
            return;
        }
        g_filesystem()->createFile($path . $fileName, json_encode($fileFields, JSON_PRETTY_PRINT));
        $this->comment("\nSchema File saved: ");
        $this->info($fileName);
    }

    protected function saveLocaleFile()
    {
        $locales = [
            'singular' => $this->config->modelNames->name,
            'plural' => $this->config->modelNames->plural,
            'fields' => [],
        ];

        foreach ($this->config->fields as $field) {
            $locales['fields'][$field->name] = Str::title(str_replace('_', ' ', $field->name));
        }

        $path = lang_path('en/models/');

        $fileName = $this->config->modelNames->snakePlural . '.php';

        if (file_exists($path . $fileName) && !$this->confirmOverwrite($fileName)) {
            return;
        }

        $locales = VarExporter::export($locales);
        $end = ';' . infy_nl();
        $content = "<?php\n\nreturn " . $locales . $end;
        g_filesystem()->createFile($path . $fileName, $content);
        $this->comment("\nModel Locale File saved.");
        $this->info($fileName);
    }

    protected function confirmOverwrite(string $fileName, string $prompt = ''): bool
    {
        $prompt = (empty($prompt))
            ? $fileName . ' already exists. Do you want to overwrite it? [y|N]'
            : $prompt;

        return $this->confirm($prompt, false);
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    public function getOptions()
    {
        return [
            ['fieldsFile', null, InputOption::VALUE_REQUIRED, 'Fields input as json file'],
            ['jsonFromGUI', null, InputOption::VALUE_REQUIRED, 'Direct Json string while using GUI interface'],
            ['plural', null, InputOption::VALUE_REQUIRED, 'Plural Model name'],
            ['table', null, InputOption::VALUE_REQUIRED, 'Table Name'],
            ['fromTable', null, InputOption::VALUE_NONE, 'Generate from existing table'],
            ['ignoreFields', null, InputOption::VALUE_REQUIRED, 'Ignore fields while generating from table'],
            ['primary', null, InputOption::VALUE_REQUIRED, 'Custom primary key'],
            ['prefix', null, InputOption::VALUE_REQUIRED, 'Prefix for all files'],
            ['skip', null, InputOption::VALUE_REQUIRED, 'Skip Specific Items to Generate (migration,model,controllers,api_controller,scaffold_controller,repository,requests,api_requests,scaffold_requests,routes,api_routes,scaffold_routes,views,tests,menu,dump-autoload)'],
            ['views', null, InputOption::VALUE_REQUIRED, 'Specify only the views you want generated: index,create,edit,show'],
            ['relations', null, InputOption::VALUE_NONE, 'Specify if you want to pass relationships for fields'],
            ['forceMigrate', null, InputOption::VALUE_NONE, 'Specify if you want to run migration or not'],
            ['connection', null, InputOption::VALUE_REQUIRED, 'Specify connection name'],
        ];
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['model', InputArgument::REQUIRED, 'Singular Model name'],
        ];
    }

    public function getFields()
    {
        $this->config->fields = [];

        if ($this->option('fieldsFile')) {
            $this->parseFieldsFromJsonFile();

            return;
        }

        if ($this->option('jsonFromGUI')) {
            $this->parseFieldsFromGUI();

            return;
        }

        if ($this->option('fromTable')) {
            $this->parseFieldsFromTable();

            return;
        }

        $this->getFieldsFromConsole();
    }

    protected function getFieldsFromConsole()
    {
        $this->info('Specify fields for the model (skip id & timestamp fields, we will add it automatically)');
        $this->info('Read docs carefully to specify field inputs)');
        $this->info('Enter "exit" to finish');

        $this->addPrimaryKey();
        $previous_properties = "";
        while (true) {

            $relation = '';
            $options = "";
            $validations = "required";

            $autoComplete_options = [
                ".hints",
                ":hasMany-",
                ":belongsTo-",
                ":hasOne-",
                ":str:",
                ":int:",
                ":bool:",
                '.exit',

            ];

            $text_color = [
                "yellow" => "\033[33m",
                "blue" => "\033[34m",
                "reset_color" => "\033[0m",
            ];

            $hints = [
                ".hints" => "All sugestions start with {$text_color['blue']}:{$text_color['reset_color']}",
                ":belongsTo-" => "{$text_color['blue']}:belongsTo-{$text_color['reset_color']}{$text_color['yellow']}author {$text_color['reset_color']} results in a model property {$text_color['yellow']}author_id{$text_color['reset_color']} that must exist AND be an int in the authors table ",
                ":hasOne-" => "{$text_color['blue']}:hasOne-{$text_color['reset_color']}{$text_color['yellow']}author {$text_color['reset_color']} results in a model property {$text_color['yellow']}author_id{$text_color['reset_color']} that must exist in the authors table ",
                ":str:" => "{$text_color['blue']}:str:{$text_color['reset_color']}{$text_color['yellow']}name{$text_color['reset_color']} results in a model property {$text_color['yellow']}name{$text_color['reset_color']} and that must be a string",
                ":int:" => "{$text_color['blue']}:int:{$text_color['reset_color']}{$text_color['yellow']}amount{$text_color['reset_color']} results in a model property {$text_color['yellow']}amount{$text_color['reset_color']} and that must be a integer",
                ":bool:" => "{$text_color['blue']}:bool:{$text_color['reset_color']}{$text_color['yellow']}active{$text_color['reset_color']} results in a model property {$text_color['yellow']}active{$text_color['reset_color']} and that must be a boolean",
            ];

            $hint = "{$text_color['yellow']}(type '.exit' to stop or '.hints' for help){$text_color['reset_color']}";
            $navigation = "{$text_color['yellow']}(you can navigate suggestions with up/down arrows){$text_color['reset_color']}";
            $property_name = $this->anticipate("What is the name of the property? \n$hint", $autoComplete_options);

            if ($property_name == ".exit") {
                break;
            }


            if ($property_name == ".hints") {
                foreach ($autoComplete_options as $option) {
                    $this->line("==================");
                    $this->line("  {$text_color['blue']}$option{$text_color['reset_color']}");
                    if (array_key_exists($option, $hints)) {
                        $this->line("   => $hints[$option]");
                    }
                    $this->line("");
                }
                continue;
            }

            $property_name_has_complex_definition = Str::contains($property_name, '-');
            try{
                if ($property_name_has_complex_definition) {

                    $validations .= "|numeric";
                    $property_info = $this->generatePropertyNameAndRelation($property_name);
                    $validations .= $property_info['foreign_validator'];
                    $property_name = $property_info['field_name'];
                    $db_type = "foreignId:constrained";
                    $html_type = GeneratorField::HTML_TYPE_SUGESTIONS[$db_type]["default"];
                    $relation = $property_info['relation'];
                }
            }catch (ErrorException $exception){
                $this->error("property name can NOT be empty ".json_encode($exception));
            }

            $property_name_has_str = Str::contains($property_name, ':str:');
            if ($property_name_has_str) {
                $property_name = str_replace(":str:", "", $property_name);
                $db_type = MigrationGenerator::DB_TYPES['string'];
                $html_type = GeneratorField::HTML_TYPE_SUGESTIONS["text"]["default"];
            }

            $property_name_has_int = Str::contains($property_name, ':int:');
            if ($property_name_has_int) {
                $validations .= "|numeric";
                $property_name = str_replace(":int", "", $property_name);
                $db_type = MigrationGenerator::DB_TYPES["integer"];
                $html_type = GeneratorField::HTML_TYPE_SUGESTIONS["text"]["default"];
            }

            $property_name_has_bool = Str::contains($property_name, ':bool:');
            if ($property_name_has_bool) {
                $property_name = str_replace(":bool", "", $property_name);
                $db_type = MigrationGenerator::DB_TYPES["boolean"];//boolean
                $html_type = GeneratorField::HTML_TYPE_SUGESTIONS["text"]["default"];
            }


            $previous_property_name = $property_name;


            if ($property_name_has_complex_definition || $property_name_has_str || $property_name_has_int || $property_name_has_bool) {


            } else {
                $previous_property_name = $property_name;

                foreach (MigrationGenerator::DB_TYPES as $value) {
                    $this->line("- $value");
                }

                $db_type = $this->askWithCompletion("What is db_type of {$text_color['yellow']}$property_name{$text_color['reset_color']} ? \n$navigation", MigrationGenerator::DB_TYPES, MigrationGenerator::DB_TYPES['string']);


                if (array_key_exists($db_type, GeneratorField::HTML_TYPE_SUGESTIONS)) {
                    foreach (GeneratorField::HTML_TYPE_SUGESTIONS[$db_type] as $key => $value) {
                        if ($key !== "default") {
                            $this->line("- $value");
                        }
                    }

                    $html_type = $this->askWithCompletion("     What is the html_type of property \n$navigation", GeneratorField::HTML_TYPE_SUGESTIONS[$db_type], GeneratorField::HTML_TYPE_SUGESTIONS[$db_type]["default"]);


                    foreach (GeneratorField::DB_OPTION_TYPES as $key => $value) {
                        $this->line("$key - $value");
                    }
                    $this->line("Example \"2\"  \"2,3\"  \"4,2,1\" ");
                    $options_selected = $this->choice(
                        'Options? (Mulitiple)',
                        array_keys(GeneratorField::DB_OPTION_TYPES),
                        0,
                        $maxAttempts = null,
                        $allowMultipleSelections = true
                    );


                    if (in_array("NO", $options_selected) && count($options_selected) > 1) {
                        $options = \Arr::join($options_selected, ",");
                        $options = str_replace(",NO", "", $options);
                        $options = str_replace("NO,", "", $options);
                    } else {
                        $options = \Arr::join($options_selected, ",");
                        $options = str_replace("NO", "", $options);
                    }
                } else {
                    $html_type = "text";
                }
            }


            $fieldInputStr = $property_name . " " . $db_type . " " . $html_type . " " . $options;


            if (empty($fieldInputStr) || $fieldInputStr == false || $fieldInputStr == 'exit') {
                break;
            }

            if (!GeneratorFieldsInputUtil::validateFieldInput($fieldInputStr)) {
                $this->error('Invalid Input. Try again');
                continue;
            }


            if (!isset($validations)) {

                $validations = $this->ask('Enter validations: ', 'required');
                $validations = ($validations == false) ? '' : $validations;
            }
            if ($this->option('relations')) {

                $relation = $this->ask('Enter relationship (Leave Blank to skip):', false);
            } else {

            }

            $this->config->fields[] = GeneratorField::parseFieldFromConsoleInput(
                $fieldInputStr,
                $validations
            );

            if (!empty($relation)) {
                $this->config->relations[] = GeneratorFieldRelation::parseRelation($relation);
            }
            $previous_properties .= "\n {$property_info['relationships_map_key']} -  {$text_color['blue']}" . $previous_property_name . " {$text_color['yellow']}" . $db_type . " " . $html_type . " " . $options."{$text_color['reset_color']}";
            $this->info("{$text_color['yellow']}Previous Properties:{$text_color['reset_color']}" . $previous_properties);
        }

        if (config('laravel_generator.timestamps.enabled', true)) {
            $this->addTimestamps();
        }


    }

    /**
     * @param $property_name_raw
     * @return array|null [$property_name,$relation]
     */
    public function generatePropertyNameAndRelation($property_name_raw): ?array
    {
        $relationships_map = [
            ":belongsTo" => "mt1",
            ":belongsToMany" => "mtm",
            ":hasOne" => "1t1",
            ":hasMany" => "1tm",

        ];
        //converts relationsship type
        $property_name_array = explode("-", $property_name_raw);
        $relationships_map_key = $property_name_array[0];
        $property_name = $property_name_array[1];


        if (array_key_exists($relationships_map_key, $relationships_map)) {
            $foreign_validator = '';
            $relationship_type = $relationships_map[$relationships_map_key];

            $field_name = lcfirst($property_name) . "_id";

            $model_name = ucfirst($property_name);

            $relation_array = [$relationship_type, $model_name, $field_name, "id"];

            $relation = \Arr::join($relation_array, ",");
            if ($relationship_type === $relationships_map[":belongsTo"]) {
                $foreign_validator = "|exists:" . Str::plural(strtolower($model_name)) . ",id";
            }
            if ($relationship_type === $relationships_map[":hasOne"]) {
                $foreign_validator = "|exists:" . Str::plural(strtolower($model_name)) . ",id";
            }



            $outcome = compact('field_name', 'relation', 'foreign_validator',"relationships_map_key");
            return $outcome;

        } else {
            return null;
        }


    }

    private function addPrimaryKey()
    {
        $primaryKey = new GeneratorField();
        if ($this->option('primary')) {
            $primaryKey->name = $this->option('primary') ?? 'id';
        } else {
            $primaryKey->name = 'id';
        }
        $primaryKey->parseDBType('id');
        $primaryKey->parseOptions('s,f,p,if,ii');

        $this->config->fields[] = $primaryKey;
    }

    private function addTimestamps()
    {
        $createdAt = new GeneratorField();
        $createdAt->name = 'created_at';
        $createdAt->parseDBType('timestamp');
        $createdAt->parseOptions('s,f,if,ii');
        $this->config->fields[] = $createdAt;

        $updatedAt = new GeneratorField();
        $updatedAt->name = 'updated_at';
        $updatedAt->parseDBType('timestamp');
        $updatedAt->parseOptions('s,f,if,ii');
        $this->config->fields[] = $updatedAt;
    }

    protected function parseFieldsFromJsonFile()
    {
        $fieldsFileValue = $this->option('fieldsFile');
        if (file_exists($fieldsFileValue)) {
            $filePath = $fieldsFileValue;
        } elseif (file_exists(base_path($fieldsFileValue))) {
            $filePath = base_path($fieldsFileValue);
        } else {
            $schemaFileDirector = config(
                'laravel_generator.path.schema_files',
                resource_path('model_schemas/')
            );
            $filePath = $schemaFileDirector . $fieldsFileValue;
        }

        if (!file_exists($filePath)) {
            $this->error('Fields file not found');
            exit;
        }

        $fileContents = g_filesystem()->getFile($filePath);
        $jsonData = json_decode($fileContents, true);
        $this->config->fields = [];
        foreach ($jsonData as $field) {
            $this->config->fields[] = GeneratorField::parseFieldFromFile($field);

            if (isset($field['relation'])) {
                $this->config->relations[] = GeneratorFieldRelation::parseRelation($field['relation']);
            }
        }
    }

    protected function parseFieldsFromGUI()
    {
        $fileContents = $this->option('jsonFromGUI');
        $jsonData = json_decode($fileContents, true);

        // override config options from jsonFromGUI
        $this->config->overrideOptionsFromJsonFile($jsonData);

        // Manage custom table name option
        if (isset($jsonData['tableName'])) {
            $tableName = $jsonData['tableName'];
            $this->config->tableName = $tableName;
            $this->config->addDynamicVariable('$TABLE_NAME$', $tableName);
            $this->config->addDynamicVariable('$TABLE_NAME_TITLE$', Str::studly($tableName));
        }

        // Manage migrate option
        if (isset($jsonData['migrate']) && $jsonData['migrate'] == false) {
            $this->config->options['skip'][] = 'migration';
        }

        foreach ($jsonData['fields'] as $field) {
            if (isset($field['type']) && $field['relation']) {
                $this->config->relations[] = GeneratorFieldRelation::parseRelation($field['relation']);
            } else {
                $this->config->fields[] = GeneratorField::parseFieldFromFile($field);
                if (isset($field['relation'])) {
                    $this->config->relations[] = GeneratorFieldRelation::parseRelation($field['relation']);
                }
            }
        }
    }

    protected function parseFieldsFromTable()
    {
        $tableName = $this->config->tableName;

        $ignoredFields = $this->option('ignoreFields');
        if (!empty($ignoredFields)) {
            $ignoredFields = explode(',', trim($ignoredFields));
        } else {
            $ignoredFields = [];
        }

        $tableFieldsGenerator = new TableFieldsGenerator($tableName, $ignoredFields, $this->config->connection);
        $tableFieldsGenerator->prepareFieldsFromTable();
        $tableFieldsGenerator->prepareRelations();

        $this->config->fields = $tableFieldsGenerator->fields;
        $this->config->relations = $tableFieldsGenerator->relations;
    }

    private function prepareEventsData(): array
    {
        return [
            'modelName' => $this->config->modelNames->name,
            'tableName' => $this->config->tableName,
            'nsModel' => $this->config->namespaces->model,
        ];
    }

    public function fireFileCreatingEvent($commandType)
    {
        event(new GeneratorFileCreating($commandType, $this->prepareEventsData()));
    }

    public function fireFileCreatedEvent($commandType)
    {
        event(new GeneratorFileCreated($commandType, $this->prepareEventsData()));
    }

    public function fireFileDeletingEvent($commandType)
    {
        event(new GeneratorFileDeleting($commandType, $this->prepareEventsData()));
    }

    public function fireFileDeletedEvent($commandType)
    {
        event(new GeneratorFileDeleted($commandType, $this->prepareEventsData()));
    }

}
