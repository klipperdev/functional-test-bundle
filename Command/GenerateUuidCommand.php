<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Command;

use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to generate a uud v4.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class GenerateUuidCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('generate:uuid')
            ->setDescription('Generate a uuid')
        ;
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(Uuid::uuid4()->toString());

        return 0;
    }
}
