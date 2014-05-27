<?php
/**
 * File containing the DeleteContentCommand class.
 *
 * Useful when you have a large DB, or a lot of content to delete
 * and the admin interface cannot cope.
 *
 * WARNING. This will delete stuff. A LOT OF STUFF, if you are not careful
 *
 * @author Gareth Arnott
 */
namespace Gaz\MiscBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputOption;

class DeleteContentCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName( 'migration:delete_content' )->setDefinition(
            array(
                new InputArgument( 'contentIds' , InputArgument::REQUIRED, 'the content to be updated' ),
            )
        )
        ->setDescription( 'Deletes content. Will delete the content object, and all locations. <error>WARNING:</error> This can and will kill your database!' )
        ->setHelp(
<<<EOT
<error>FINAL WARNING:</error> <question>Delete the wrong object and you will break your site.</question>
CSV Format (no header):
<comment>123</comment>,<comment>546</comment>
EOT
            );
    }

    protected function execute( InputInterface $input, OutputInterface $output )
    {
        /** @var $repository \eZ\Publish\API\Repository\Repository */
        $repository = $this->getContainer()->get( 'ezpublish.api.repository' );
        $contentService = $repository->getContentService();

        $repository->setCurrentUser( $repository->getUserService()->loadUser( 14 ) );

        // @todo this is a bit naff
        $contentIds = $input->getArgument( 'contentIds' );
        $contentIds = explode( ',', $contentIds );
        // remove empty commas
        $contentIds = array_filter( $contentIds );

        foreach( $contentIds as $contentId ) {
            try {
                $contentInfo = $contentService->loadContentInfo($contentId);
                $contentService->deleteContent($contentInfo);
                $output->write( $contentId );
                $output->writeln( ' - aww, byebye content.' );
            } catch (\eZ\Publish\API\Repository\Exceptions\NotFoundException $e) {
                $output->writeln($e->getMessage());
            }
        }
    }
}
