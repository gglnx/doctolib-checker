<?php

namespace DoctolibChecker;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Source\Source;
use CuyZ\Valinor\Mapper\Tree\Message\Messages;
use CuyZ\Valinor\MapperBuilder;
use DateTimeImmutable;
use DoctolibChecker\Dto\Availabilities;
use DoctolibChecker\Dto\Config;
use DoctolibChecker\Dto\Doctor;
use DoctolibChecker\Exception\AvailabilitiesException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Filesystem\Exception\InvalidArgumentException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Notifier\Bridge\Telegram\Reply\Markup\Button\InlineKeyboardButton;
use Symfony\Component\Notifier\Bridge\Telegram\Reply\Markup\InlineKeyboardMarkup;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class DoctolibChecker extends SingleCommandApplication
{
    protected function configure(): void
    {
        $this->setName('Doctolib Checker');
        $this->setVersion('1.0.0');

        $this->addArgument(
            name: 'config',
            mode: InputArgument::REQUIRED,
            description: 'Path to configuration',
            suggestedValues: ['config.yaml'],
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get configuration
        try {
            $configPath = Path::makeAbsolute(
                Path::canonicalize($input->getArgument('config')),
                getcwd() ?: __DIR__,
            );

            $configSource = Source::array(Yaml::parseFile($configPath))
                ->camelCaseKeys();

            $config = (new MapperBuilder())->mapper()->map(
                Config::class,
                $configSource,
            );
        } catch (InvalidArgumentException | ParseException | MappingError) {
            $output->writeln('<error>Invalid configuration path or configuration provided.</error>');
            return Command::FAILURE;
        }

        // Check doctors
        foreach ($config->doctors as $doctor) {
            try {
                $availabilities = $this->getAvailabilitiesForDoctor($doctor);

                if ($availabilities->total > 0) {
                    $slots = $availabilities->getSlots(3);

                    foreach ($config->transports as $transport) {
                        $message = $this->buildMessage($transport, $doctor, $slots);

                        try {
                            $transport->send($message);
                        } catch (TransportException) {
                            // Skip.
                        }
                    }
                }
            } catch (AvailabilitiesException $e) {
                foreach ($config->transports as $transport) {
                    $message = $this->buildErrorMessage($transport, $doctor, $e->getMessage());

                    try {
                        $transport->send($message);
                    } catch (TransportException) {
                        // Skip.
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param TransportInterface $transport
     * @param Doctor $doctor
     * @param string $message
     * @return ChatMessage
     */
    private function buildErrorMessage(TransportInterface $transport, Doctor $doctor, string $message): ChatMessage
    {
        return new ChatMessage("Fetching for {$doctor->name} failed: {$message}");
    }

    /**
     * @param TransportInterface $transport
     * @param Doctor $doctor
     * @param DateTimeImmutable[] $slots
     * @return ChatMessage
     */
    private function buildMessage(TransportInterface $transport, Doctor $doctor, array $slots): ChatMessage
    {
        $message = ["New slots for {$doctor->name}:"];
        foreach ($slots as $slot) {
            $message[] = $slot->format('d.m.Y H:i');
        }

        $chatMessage = new ChatMessage(implode("\n", $message));

        if ($transport instanceof TelegramTransport) {
            $telegramOptions = (new TelegramOptions())
                ->parseMode('MarkdownV2')
                ->disableWebPagePreview(true)
                ->replyMarkup(
                    (new InlineKeyboardMarkup())->inlineKeyboard([
                        (new InlineKeyboardButton('Open booking page'))->url($doctor->url),
                    ]),
                );

            $chatMessage->options($telegramOptions);
        }

        return $chatMessage;
    }

    /**
     * Gets availabilities for a doctor
     *
     * @param Doctor $doctor
     * @return Availabilities
     */
    private function getAvailabilitiesForDoctor(Doctor $doctor): Availabilities
    {
        try {
            $response = (new GuzzleClient())->get('https://www.doctolib.de/availabilities.json', [
                'query' => [
                    'visit_motive_ids' => $doctor->visitMotiveId,
                    'agenda_ids' => $doctor->agendaId,
                    'practice_ids' => $doctor->practiceId,
                    'insurance_sector' => $doctor->insuranceSector->value,
                    'limit' => 15,
                    'start_date' => date('Y-m-d'),
                ],
                'headers' => [
                    'User-Agent' =>
                        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0',
                    'Accept' => 'application/json',
                    'Accept-Language' => 'de,en-US;q=0.7,en;q=0.3',
                    'Referer' => $doctor->url,
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Connection' => 'keep-alive',
                    'Sec-Fetch-Dest' => 'empty',
                    'Sec-Fetch-Mode => cors',
                    'Sec-Fetch-Site => same-origin',
                    'Priority' => 'u=4',
                ],
            ]);

            $mapperBuilder = (new MapperBuilder())
                ->allowPermissiveTypes()
                ->enableFlexibleCasting()
                ->allowSuperfluousKeys();

            $mapperBuilder = $mapperBuilder->supportDateFormats(
                ...$mapperBuilder->supportedDateFormats(),
                ...['Y-m-d'],
            );

            return $mapperBuilder->mapper()->map(
                Availabilities::class,
                json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR),
            );
        } catch (MappingError $e) {
            $messages = Messages::flattenFromNode($e->node());
            $errors = [];

            foreach ($messages->errors() as $error) {
                $errors[] = (string) $error->withBody('[{node_path}] {original_message}');
            }

            throw new AvailabilitiesException(
                "Could not map response, errors:\n" . implode("\n", $errors),
                previous: $e,
            );
        } catch (GuzzleException | JsonException $e) {
            throw new AvailabilitiesException($e->getMessage(), previous: $e);
        }
    }
}
