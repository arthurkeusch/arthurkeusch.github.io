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
 * Pour exécuter la commande :
 *
 * php bin/console app:get-thread
 */
#[AsCommand(name: 'app:get-thread')]
class GetThreadCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Permet de sauvegarder les threads.')
            ->setHelp('Cette commande permet de sauvegarder les threads');
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isError = false;
        $nbErrors = 0;
        $output->writeln("Récupération des assistants à tester...");
        try {
            $assistants = $this->request("
SELECT *
FROM Assistants
WHERE isActive = TRUE;
", true);
        } catch (Exception $e) {
            $output->writeln("");
            $output->writeln("<error>Erreur lors de la récupération des assistants à tester !</error>");
            $output->writeln("$e");
            return Command::FAILURE;
        }

        $progressBar = new ProgressBar($output, count($assistants));
        $output->writeln("Tests des assistants...");
        $progressBar->start();

        foreach ($assistants as $assistant) {
            $id_assistant_BDD = $assistant['id_assistant'];
            $assistant = json_decode($assistant['object_assistant']);
            $id_assistant = $assistant->id;
            try {
                $instructions_assistant = $this->curlRequest("https://api.openai.com/v1/assistants/" . $id_assistant, [
                    [CURLOPT_RETURNTRANSFER, true],
                    [CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $_ENV['API_KEY'],
                        'OpenAI-Beta: assistants=v2']
                    ],
                    [CURLOPT_SSL_VERIFYPEER, false]
                ]);
                $instructions_assistant = json_decode($instructions_assistant);
                $this->updateAssistant($id_assistant_BDD, $instructions_assistant);
                $instructions_assistant = $instructions_assistant->instructions;
                try {
                    $thread = $this->curlRequest("https://api.openai.com/v1/threads", [
                        [CURLOPT_RETURNTRANSFER, true],
                        [CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $_ENV['API_KEY'],
                            'OpenAI-Beta: assistants=v2']
                        ],
                        [CURLOPT_POST, true],
                        [CURLOPT_SSL_VERIFYPEER, false]
                    ]);
                    $thread = json_decode($thread, true);
                    try {
                        $run = $this->curlRequest("https://api.openai.com/v1/threads/" . $thread['id'] . "/runs", [
                            [CURLOPT_RETURNTRANSFER, true],
                            [CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $_ENV['API_KEY'],
                                'OpenAI-Beta: assistants=v2']
                            ],
                            [CURLOPT_POST, true],
                            [CURLOPT_POSTFIELDS, json_encode(
                                [
                                    'assistant_id' => $id_assistant
                                ])
                            ],
                            [CURLOPT_SSL_VERIFYPEER, false]
                        ]);
                        $run = json_decode($run);
                        $isNotFinish = true;
                        $start = microtime(true);
                        $nbErrorRun = 0;
                        while ($isNotFinish) {
                            try {
                                $status_run = $this->curlRequest("https://api.openai.com/v1/threads/" . $thread['id'] . "/runs/" . $run->id, [
                                    [CURLOPT_RETURNTRANSFER, true],
                                    [CURLOPT_HTTPHEADER, [
                                        'Content-Type: application/json',
                                        'Authorization: Bearer ' . $_ENV['API_KEY'],
                                        'OpenAI-Beta: assistants=v2']
                                    ],
                                    [CURLOPT_SSL_VERIFYPEER, false]
                                ]);
                                $status_run = json_decode($status_run, true);
                                if ($status_run['status'] !== 'in_progress' && $status_run['status'] !== 'queued') {
                                    $isNotFinish = false;
                                }
                            } catch (Exception $e) {
                                $nbErrorRun++;
                                sleep(1);
                                if ($nbErrorRun > 10) {
                                    $output->writeln("");
                                    $output->writeln("<error>Impossible de récupérer le status du thread !</error>");
                                    $output->writeln("$e");
                                    return Command::FAILURE;
                                }
                            }
                        }
                        $end = microtime(true);
                        $latency = $end - $start;
                        if ($status_run['status'] !== 'completed' && $status_run['status'] !== 'incomplete') {
                            $this->insertNewThread($thread, $status_run, 'NULL', $latency * 1000, 1, $instructions_assistant, 8, $id_assistant_BDD);
                        } else {
                            try {
                                $list_messages = $this->curlRequest("https://api.openai.com/v1/threads/" . $thread['id'] . "/messages", [
                                    [CURLOPT_RETURNTRANSFER, true],
                                    [CURLOPT_HTTPHEADER, [
                                        'Content-Type: application/json',
                                        'Authorization: Bearer ' . $_ENV['API_KEY'],
                                        'OpenAI-Beta: assistants=v2']
                                    ],
                                    [CURLOPT_SSL_VERIFYPEER, false]
                                ]);
                                try {
                                    if ($status_run['status'] === 'completed') {
                                        $this->insertNewThread($thread, $status_run, $list_messages, $latency * 1000, 0, $instructions_assistant, null, $id_assistant_BDD);
                                    } else {
                                        $this->insertNewThread($thread, $status_run, $list_messages, $latency * 1000, 1, $instructions_assistant, 7, $id_assistant_BDD);
                                    }
                                } catch (Exception $e) {
                                    $output->writeln("");
                                    $output->writeln("<error>Erreur lors de l'enregistrement dans la base de données !</error>");
                                    $output->writeln("$e");
                                    $isError = true;
                                    $nbErrors++;
                                }
                            } catch (Exception $e) {
                                $output->writeln("");
                                $output->writeln("<error>Erreur lors de la récupération des messages du run !</error>");
                                $output->writeln("$e");
                                $isError = true;
                                $nbErrors++;
                                try {
                                    $this->insertNewThread($thread, $status_run, 'NULL', $latency * 1000, 1, $instructions_assistant, 9, $id_assistant_BDD);
                                } catch (Exception $e) {
                                    $output->writeln("");
                                    $output->writeln("<error>Erreur lors de l'enregistrement dans la base de données !</error>");
                                    $output->writeln("$e");
                                }
                            }
                        }
                        try {
                            $response = false;
                            while ($response) {
                                $delete_response = $this->curlRequest("https://api.openai.com/v1/threads/" . $thread['id'], [
                                    [CURLOPT_RETURNTRANSFER, true],
                                    [CURLOPT_HTTPHEADER, [
                                        'Content-Type: application/json',
                                        'Authorization: Bearer ' . $_ENV['API_KEY'],
                                        'OpenAI-Beta: assistants=v2'
                                    ]],
                                    [CURLOPT_SSL_VERIFYPEER, false],
                                    [CURLOPT_CUSTOMREQUEST, 'DELETE']
                                ]);
                                $response = json_decode($delete_response)->deleted;
                            }
                        } catch (Exception $e) {
                            $output->writeln("");
                            $output->writeln("<error>Erreur lors de la suppression du thread !</error>");
                            $output->writeln("$e");
                            $isError = true;
                            $nbErrors++;
                        }
                    } catch (Exception $e) {
                        $output->writeln("");
                        $output->writeln("<error>Erreur lors de la création du run !</error>");
                        $output->writeln("$e");
                        $isError = true;
                        $nbErrors++;
                    }
                } catch (Exception $e) {
                    $output->writeln("");
                    $output->writeln("<error>Erreur lors de la création du thread !</error>");
                    $output->writeln("$e");
                    $isError = true;
                    $nbErrors++;
                }
            } catch (Exception $e) {
                $output->writeln("");
                $output->writeln("<error>Erreur lors de la récupération de l'assistant !</error>");
                $output->writeln("$e");
                $isError = true;
                $nbErrors++;
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $io->newLine();

        if ($isError) {
            $output->writeln("<comment>$nbErrors erreurs sont survenues !</>");
            return Command::FAILURE;
        } else {
            $output->writeln('<info>Tous les assistants ont été testés avec succès !</>');
            return Command::SUCCESS;
        }
    }

    /**
     * @throws Exception
     */
    private function curlRequest($to, $options): string
    {
        if ($to === null || $to === "") {
            throw new Exception("<error>L'URL n'a pas été renseignée !</>\n");
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
    private function insertNewThread($object_thread, $object_run, $object_list_messages, $latency_thread, $isError, $instructions_assistant, $id_error_type, $id_assistant): void
    {
        try {
            $dbco = $this->getPDO();
            $stmt = $dbco->prepare("
INSERT INTO Threads(date_thread, object_thread, object_run, object_list_messages, latency_thread, isError, instructions_assistant, id_error_type, id_assistant)
VALUES (CURRENT_TIMESTAMP, :object_thread, :object_run, :object_list_messages, :latency_thread, :isError, :instructions_assistant, :id_error_type, :id_assistant);
");
            $object_thread_json = json_encode($object_thread);
            $object_run_json = json_encode($object_run);
            $object_list_messages_json = is_array($object_list_messages) ? json_encode($object_list_messages) : $object_list_messages;
            $instructions_assistant_json = json_encode($instructions_assistant);
            $stmt->bindParam(':object_thread', $object_thread_json);
            $stmt->bindParam(':object_run', $object_run_json);
            $stmt->bindParam(':object_list_messages', $object_list_messages_json);
            $stmt->bindParam(':latency_thread', $latency_thread);
            $stmt->bindParam(':isError', $isError);
            $stmt->bindParam(':instructions_assistant', $instructions_assistant_json);
            $stmt->bindParam(':id_error_type', $id_error_type);
            $stmt->bindParam(':id_assistant', $id_assistant);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new PDOException($e);
        }
    }

    /**
     * @throws PDOException
     */
    private function updateAssistant($id_assistant, $object_assistant): void
    {
        try {
            $dbco = $this->getPDO();
            $stmt = $dbco->prepare("
UPDATE Assistants
SET object_assistant = :object_assistant
WHERE id_assistant = :id_assistant;
");
            $object_assistant = json_encode($object_assistant);
            $stmt->bindParam(':object_assistant', $object_assistant);
            $stmt->bindParam(':id_assistant', $id_assistant);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new PDOException($e);
        }
    }
}