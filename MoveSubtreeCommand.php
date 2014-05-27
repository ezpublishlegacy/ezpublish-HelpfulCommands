<?php
/**
 * Command to move locations from one subtree to another.
 *
 * Script is basic - locationId's must exist in the database before running.
 *
 * @author Gareth Arnott
 */

namespace Gaz\MiscBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class MoveSubtreeCommand extends ContainerAwareCommand{
    protected function configure()
    {
        $this->setName( 'migration:move_subtree' )
            ->setDefinition(
                array(
                    new InputOption( '--input', '-i', InputOption::VALUE_REQUIRED, 'A CSV that details which locationId\'s to move to which parents' ),
                ))
            ->setDescription( 'Moves a subtree from one location to another. Both locations must exist.' )
            ->setHelp(
                <<<EOT
    <info>Required CSV format: </info>
    <question>locationId</question>,<question>newParentLocationId</question>,
    <comment>123</comment>,<comment>546</comment>,
EOT
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute( InputInterface $input, OutputInterface $output ){
        /** @var $repository \eZ\Publish\API\Repository\Repository */
        $repository = $this->getContainer()->get( 'ezpublish.api.repository' );
        $locationService = $repository->getLocationService();

        $adminUserId = $this->getContainer()->get( 'ezpublish.config.resolver' )->getParameter( 'user_id.admin', 'gazofnaz' );
        $repository->setCurrentUser( $userService = $repository->getUserService()->loadUser( $adminUserId ) );

        $inputFile = $input->getOption( 'input' );

        try {
            $locationsToMoveArray = $this->csvToArray($inputFile);
        }
        catch( FileNotFoundException $e ){
            $output->writeln( '<error>No CSV file found.</error>' );
            return;
        }

        $progress = $this->getHelperSet()->get( 'progress' );

        $count = count( $locationsToMoveArray );
        $progress->start( $output, $count );
        $progress->setBarWidth( 60 );

        if( !isset( $locationsToMoveArray ) ) {
            $output->writeln( '<error>The specified input file is empty or invalid.</error>' );
            return;
        }

        $results = array();

        foreach( $locationsToMoveArray as $locationIds ){

            $locationId          = $locationIds['locationId'];
            $newParentLocationId = $locationIds['newParentLocationId'];

            $results[] = $this->moveLocationSubtree(
                $locationId,
                $newParentLocationId,
                $locationService,
                $output );

            $progress->advance();

        }

        $progress->finish();

        // filter null, they were successful results
        $results = array_filter( $results );

        // finished, print outcome
        if( empty($results) ){
            $output->writeln( '<info>All locations moved successfully</info>' );
        }
        else{
            $output->writeln( '<error>The following errors were reported:</error>' );
            foreach( $results as $result ){
                $output->writeln( "<error>$result</error>" );
            }
        }

    }

    /**
     * @param $locationId
     * @param $newParentLocationId
     * @param \eZ\Publish\API\Repository\LocationService $locationService
     * @param OutputInterface $output
     * @return mixed
     */
    protected function moveLocationSubtree(
        $locationId,
        $newParentLocationId,
        \eZ\Publish\API\Repository\LocationService $locationService,
        OutputInterface $output ){

        // only want to track errors. null for success is easy to filter
        $result = null;

        try {
            $moveableLocation = $locationService->loadLocation( $locationId );
            $newParentLocation = $locationService->loadLocation( $newParentLocationId );
            $locationService->moveSubtree( $moveableLocation, $newParentLocation );
        }
        // Permission denied
        catch ( \eZ\Publish\API\Repository\Exceptions\UnauthorizedException $e ){
            $result = sprintf( "Error moving %s ==> %s. \n %s", $locationId, $newParentLocationId, $e->getMessage() );
        }
        // Content is already below the specified parent.
        catch ( \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException $e ){
            $result = sprintf( "Error moving %s ==> %s. \n %s", $locationId, $newParentLocationId, $e->getMessage() );
        }
        // Content is already below the specified parent.
        catch ( \eZ\Publish\API\Repository\Exceptions\NotFoundException $e ){
            $result = sprintf( "Error moving %s ==> %s. \n %s", $locationId, $newParentLocationId, $e->getMessage() );
        }
        // Happened trying to add subtree to itself locally
        catch( \RuntimeException $e ){
            $result = sprintf( "Error moving %s ==> %s. \n %s", $locationId, $newParentLocationId, $e->getMessage() );
        }

        // tracking errors for nice print at the end
        return $result;
    }

     /**
     * Parse csv file to array.
     *
     * Copied from php.net example
     *
     * @see http://www.php.net/manual/en/function.str-getcsv.php
     *
     * @throws FileNotFoundException
     *
     * @param string $filename
     * @param string $delimiter
     * @return array
     */
    private function csvToArray( $filename='', $delimiter=',' ){

        if( !file_exists( $filename ) || !is_readable( $filename ) ){
            throw new FileNotFoundException( $filename );
        }

        $header = null;
        $data = array();
        if (($handle = fopen($filename, 'r')) !== false){
            while (($row = fgetcsv($handle, 1000, $delimiter)) !== false){

                if(!$header){
                    $header = $row;
                }
                else{
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }

        return $data;
    }
} 
