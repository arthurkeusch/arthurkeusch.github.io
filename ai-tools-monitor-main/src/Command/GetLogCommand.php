<?php

namespace App\Command;

use Exception;
use PDO;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pour lancer la commande :
 *
 * php bin/console app:get-log
 */
#[AsCommand(name: 'app:get-log')]
class GetLogCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Permet de récupérer les logs.')
            ->setHelp('Cette commande permet de récupérer les logs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isError = false;
        $nbErrors = 0;
        $io = new SymfonyStyle($input, $output);
        $API_KEY = $_ENV['API_KEY'];
        $API_KEY_OFP = $_ENV['API_KEY_OFP'];

        $output->writeln('Récupération des outils à tester...');
        try {
            $actives_languages = $this->request("
                SELECT Languages.*, T.api_name_tool, GROUP_CONCAT(active_level.id_level) AS active_levels
                FROM Languages
                JOIN Tools T on T.id_tool = Languages.id_tool
                LEFT JOIN active_level ON Languages.id_language = active_level.id_language
                WHERE Languages.is_active = TRUE
                GROUP BY Languages.id_language;
            ", true);
        } catch (PDOException $e) {
            $output->writeln("");
            $output->writeln("<error>Erreur lors de la récupération des outils à tester !</error>");
            $output->writeln("$e");
            return Command::FAILURE;
        }

        $totalIterations = $this->getNbIterations($actives_languages);
        $progressBar = new ProgressBar($output, $totalIterations);
        $output->writeln('Récupération des réponses de ChatGPT...');
        $progressBar->start();

        foreach ($actives_languages as $language) {
            $levels = $this->stringToIntArray($language['active_levels']);
            foreach ($levels as $level) {
                if ($level > 0) {
                    try {
                        $id_language = $language['id_language'];
                        $level_value = $this->request("
                            SELECT level
                            FROM Levels
                            WHERE id_level = $level;
                        ", true);
                        $data = [
                            "tool" => $language['api_name_tool'],
                            "lang" => $language['short_name_language'],
                            "level" => $level_value[0]['level']
                        ];
                        try {
                            $prompt = $this->curlRequest("link_to_ofp_api", [
                                [CURLOPT_RETURNTRANSFER, true],
                                [CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Access-Token: ' . $API_KEY_OFP]],
                                [CURLOPT_POST, true],
                                [CURLOPT_POSTFIELDS, json_encode($data)],
                                [CURLOPT_SSL_VERIFYPEER, false]
                            ]);

                            try {
                                $prompt = json_decode($prompt);
                                if ($prompt === null) {
                                    throw new Exception("La réponse est vide !");
                                }

                                try {
                                    $messages = json_encode($prompt->data->messages, JSON_PRETTY_PRINT);
                                    $model = json_encode($prompt->data->model, JSON_PRETTY_PRINT);
                                    $prompt = [
                                        "model" => json_decode($model, true),
                                        "messages" => json_decode($messages, true)
                                    ];
                                    $prompt = json_encode($prompt, JSON_PRETTY_PRINT);
                                    $start = microtime(true);

                                    try {
                                        $responseGPT = $this->curlRequest("https://api.openai.com/v1/chat/completions", [
                                            [CURLOPT_RETURNTRANSFER, true],
                                            [CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $API_KEY]],
                                            [CURLOPT_POST, true],
                                            [CURLOPT_POSTFIELDS, $prompt],
                                            [CURLOPT_SSL_VERIFYPEER, false]
                                        ]);
                                        $end = microtime(true);
                                        $latency = $end - $start;

                                        try {
                                            $response = json_decode($responseGPT);
                                            if (is_object($response) && property_exists($response, "error")) {
                                                try {
                                                    $this->insertNewLog($prompt, $responseGPT, $id_language, $level, "1", $latency * 1000, 5);
                                                    $isError = true;
                                                    $nbErrors += 1;
                                                    $output->writeln("");
                                                    $output->writeln("<error>Erreur lors dans la réponse de l'API d'OpenIA !</error>");
                                                    $output->writeln("");
                                                } catch (PDOException $e) {
                                                    $isError = true;
                                                    $nbErrors += 1;
                                                    $output->writeln("");
                                                    $output->writeln("<error>Erreur lors de l'enregistrement d'un log d'erreur dans la base de donnée !</error>");
                                                    $output->writeln("$e");
                                                }
                                            }
                                            try {
                                                $this->insertNewLog($prompt, $responseGPT, $id_language, $level, "0", $latency * 1000, null);
                                            } catch (PDOException $e) {
                                                $isError = true;
                                                $nbErrors += 1;
                                                $output->writeln("");
                                                $output->writeln("<error>Erreur lors de l'enregistrement d'un log dans la base de donnée !</error>");
                                                $output->writeln("$e");
                                            }
                                        } catch (Exception $e) {
                                            $isError = true;
                                            $nbErrors += 1;
                                            $output->writeln("");
                                            $output->writeln("<error>Erreur dans la réponse de l'API d'OpenAI !</error>");
                                            $output->writeln("$e");
                                            try {
                                                $this->insertNewLog($prompt, $e, $id_language, $level, "1", $latency * 1000, 5);
                                            } catch (PDOException $e) {
                                                $output->writeln("");
                                                $output->writeln("<error>Erreur lors de la sauvegarde de l'erreur !</error>");
                                                $output->writeln("$e");
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $isError = true;
                                        $nbErrors += 1;
                                        $output->writeln("");
                                        $output->writeln("<error>Erreur lors de la requête à l'API d'OpenAI' !</error>");
                                        $output->writeln("$e");
                                        try {
                                            $this->insertNewLog($prompt, $e, $id_language, $level, "1", 0, 4);
                                        } catch (PDOException $e) {
                                            $output->writeln("");
                                            $output->writeln("<error>Erreur lors de la sauvegarde de l'erreur !</error>");
                                            $output->writeln("$e");
                                        }
                                    }
                                } catch (Exception $e) {
                                    $isError = true;
                                    $nbErrors += 1;
                                    $output->writeln("");
                                    $output->writeln("<error>Erreur dans la réponse de l'API d'OnlineFormaPro : Le format de la réponse est invalide !</error>");
                                    $output->writeln("$e");
                                    try {
                                        $this->insertNewLog($e, null, $id_language, $level, "1", 0, 3);
                                    } catch (PDOException $e) {
                                        $output->writeln("");
                                        $output->writeln("<error>Erreur lors de la sauvegarde de l'erreur !</error>");
                                        $output->writeln("$e");
                                    }
                                }
                            } catch (Exception $e) {
                                $isError = true;
                                $nbErrors += 1;
                                $output->writeln("");
                                $output->writeln("<error>Erreur dans la réponse de l'API d'OnlineFormaPro : La réponse est vide !</error>");
                                $output->writeln("$e");
                                try {
                                    $this->insertNewLog($e, null, $id_language, $level, "1", 0, 2);
                                } catch (PDOException $e) {
                                    $output->writeln("");
                                    $output->writeln("<error>Erreur lors de la sauvegarde de l'erreur !</error>");
                                    $output->writeln("$e");
                                }
                            }
                        } catch (Exception $e) {
                            $isError = true;
                            $nbErrors += 1;
                            $output->writeln("");
                            $output->writeln("<error>Erreur lors de la requête à l'API d'OnlineFormaPro !</error>");
                            $output->writeln("$e");
                            try {
                                $this->insertNewLog($e, null, $id_language, $level, "1", 0, 1);
                            } catch (PDOException $e) {
                                $output->writeln("");
                                $output->writeln("<error>Erreur lors de la sauvegarde de l'erreur !</error>");
                                $output->writeln("$e");
                            }
                        }
                    } catch (Exception $e) {
                        $isError = true;
                        $nbErrors += 1;
                        $output->writeln("");
                        $output->writeln("<error>Erreur lors de l'exécution du script !</error>");
                        $output->writeln("$e");
                        try {
                            $this->insertNewLog($e, null, $id_language, $level, "1", 0, 6);
                        } catch (PDOException $e) {
                            $output->writeln("");
                            $output->writeln("<error>Erreur lors de la sauvegarde de l'erreur !</error>");
                            $output->writeln("$e");
                        }
                    }
                    usleep($this->randomSleepTime(200000, 1000000));
                    $progressBar->advance();
                }
            }
        }
        $progressBar->finish();
        $io->newLine();
        if ($isError) {
            $output->writeln("<comment>$nbErrors erreurs sont survenus !</>");
            return Command::FAILURE;
        } else {
            $output->writeln('<info>Tous les logs ont été récupéré avec succès !</>');
            return Command::SUCCESS;
        }
    }

    /**
     * @throws PDOException
     */
    private function request(string $request, bool $isGetRequest): array|bool
    {
        try {
            $dbco = $this->getPDO();
            $sth = $dbco->prepare($request);
            $sth->execute();
            if ($isGetRequest) {
                return $sth->fetchAll();
            }
            return true;
        } catch (PDOException $e) {
            throw new PDOException($e);
        }
    }

    /**
     * @return PDO
     */
    private function getPDO(): PDO
    {
        $servname = $_ENV['SERVER'];
        $dbname = $_ENV['DB'];
        $user = $_ENV['USER'];
        $pass = $_ENV['PASSWORD'];
        $dbco = new PDO("mysql:host=$servname;dbname=$dbname", $user, $pass);
        $dbco->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $dbco;
    }

    private function getNbIterations($actives_languages): int
    {
        $nb = 0;
        foreach ($actives_languages as $language) {
            $levels = $this->stringToIntArray($language['active_levels']);
            $nb += count($levels);
        }
        return $nb;
    }

    private function stringToIntArray($string): array
    {
        $array = explode(',', $string);
        return array_map('intval', $array);
    }

    /**
     * @throws Exception
     */
    private function curlRequest($to, $options): string
    {
        if ($to === null || $to === "") {
            throw new Exception("<error>L'URL n'as pas été renseigné !</>\n");
        }
        $curl = curl_init($to);
        foreach ($options as $option) {
            curl_setopt($curl, $option[0], $option[1]);
        }
        $response = curl_exec($curl);
        if ($response === false || isset(json_decode($response)->error)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception("$error");
        }
        curl_close($curl);
        return $response;
    }

    /**
     * @throws PDOException
     */
    private function insertNewLog($params, $response, $id_language, $id_level, $error, $latency, $id_error_type): void
    {
        try {
            $dbco = $this->getPDO();
            $stmt = $dbco->prepare("
                INSERT INTO Logs(date_log, params_log, response_log, id_language, id_level, isError, latency_log, id_error_type)
                VALUES (CURRENT_TIMESTAMP, :params, :response, :id_language, :id_level, :error, :latency, :id_error_type);
            ");
            $stmt->bindParam(':params', $params);
            $stmt->bindParam(':response', $response);
            $stmt->bindParam(':id_language', $id_language);
            $stmt->bindParam(':id_level', $id_level);
            $stmt->bindParam(':error', $error);
            $stmt->bindParam(':latency', $latency);
            $stmt->bindParam(':id_error_type', $id_error_type);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new PDOException($e);
        }
    }

    private function randomSleepTime($min, $max): int
    {
        return rand($min, $max);
    }
}