<?php

namespace App\Command;

use Exception;
use PDO;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pour exécuter la commande :
 *
 * php bin/console app:get-backup-assistants
 */
#[AsCommand(name: 'app:get-backup-assistants')]
class GetBackUpAssistantsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Permet de sauvegarder les assistants.')
            ->setHelp('Cette commande permet de sauvegarder les assistants.');
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
        $isError = false;
        $nbErrors = 0;

        $output->writeln("Récupération des assistants depuis l'API d'OpenAI...");

        $list_assistants = [];

        try {
            $assistants = $this->curlRequest("https://api.openai.com/v1/assistants", [
                [CURLOPT_RETURNTRANSFER, true],
                [CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $_ENV['API_KEY'],
                    'OpenAI-Beta: assistants=v2']
                ],
                [CURLOPT_SSL_VERIFYPEER, false]
            ]);
            $assistants = json_decode($assistants);
            if (is_object($assistants) && property_exists($assistants, 'data')) {
                foreach ($assistants->data as $assistant) {
                    $list_assistants[] = $assistant;
                }
            } else {
                throw new Exception("La réponse de l'API d'OpenAI ne contient pas les éléments attendus.");
            }
            $last_id = $assistants->last_id;
            $has_more = $assistants->has_more;
        } catch (Exception $e) {
            $output->writeln("");
            $output->writeln("<error>Erreur lors de la récupération des assistants !</error>");
            $output->writeln("$e");
            return Command::FAILURE;
        }

        try {
            while ($has_more) {
                try {
                    $assistants = $this->curlRequest("https://api.openai.com/v1/assistants?orderdesc&after=" . $last_id, [
                        [CURLOPT_RETURNTRANSFER, true],
                        [CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $_ENV['API_KEY'],
                            'OpenAI-Beta: assistants=v2']
                        ],
                        [CURLOPT_SSL_VERIFYPEER, false]
                    ]);
                    $assistants = json_decode($assistants);
                    if (is_object($assistants) && property_exists($assistants, 'data')) {
                        foreach ($assistants->data as $assistant) {
                            $list_assistants[] = $assistant;
                        }
                    } else {
                        throw new Exception("La réponse de l'API d'OpenIA ne contient pas les éléments attendus.");
                    }
                    $last_id = $assistants->last_id;
                    $has_more = $assistants->has_more;
                } catch (Exception $e) {
                    $output->writeln("");
                    $output->writeln("<error>Erreur lors de la récupération des assistants !</error>");
                    $output->writeln("$e");
                    $isError = true;
                    $nbErrors++;
                }
                if ($nbErrors > 100) {
                    throw new Exception("Trop d'erreurs sont survenues ! Le script a été stoppé.");
                }
            }
        } catch (Exception $e) {
            $output->writeln("");
            $output->writeln("<error>Erreur lors de la récupération des assistants !</error>");
            $output->writeln("$e");
            return Command::FAILURE;
        }

        $output->writeln("Tous les assistants ont été récupérés !");

        $output->writeln("Enregistrement des assistants dans la base de données...");

        $id_backup = $this->insertBackUp();

        foreach ($list_assistants as $assistant) {
            $this->insertBackUpAssistant(json_encode($assistant), $id_backup);
        }

        if ($isError) {
            $output->writeln("<comment>$nbErrors erreurs sont survenues !</>");
            return Command::FAILURE;
        } else {
            $output->writeln('<info>Tous les assistants ont été enregistré avec succès !</>');
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
    private function insertBackUp(): int
    {
        try {
            $dbco = $this->getPDO();
            $stmt = $dbco->prepare("
INSERT INTO BackUp(date_backup)
VALUES (CURRENT_TIMESTAMP);
");
            $stmt->execute();
        } catch (PDOException $e) {
            throw new PDOException($e);
        }
        $id = $this->request("
SELECT id_backup
FROM BackUp
ORDER BY id_backup DESC
LIMIT 1;
", true);
        return $id[0]['id_backup'];
    }

    /**
     * @throws PDOException
     */
    private function insertBackUpAssistant($object_assistant, $id_backup): void
    {
        try {
            $dbco = $this->getPDO();
            $stmt = $dbco->prepare("
INSERT INTO BackUp_Assistants(date_backup_assistant, object_backup_assistant, id_backup)
VALUES (CURRENT_TIMESTAMP, :object_assistant, :id_backup);
");
            $stmt->bindParam(':object_assistant', $object_assistant);
            $stmt->bindParam(':id_backup', $id_backup);
            $stmt->execute();
        } catch (PDOException $e) {
            throw new PDOException($e);
        }
    }
}