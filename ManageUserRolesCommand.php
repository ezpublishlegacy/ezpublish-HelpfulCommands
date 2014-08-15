<?php
/**
 * File containing the ManageUserRolesCommand class.
 *
 * This command is a basic replacement for the ezpublish "roles" page,
 * which is unusable for environments with a large number of roles.
 *
 * @see https://jira.ez.no/browse/EZP-23100
 * @author Gareth Arnott
 */

namespace Gaz\MiscBundle\Command;

use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\RoleService;
use eZ\Publish\API\Repository\Values\User\Limitation\SubtreeLimitation;
use eZ\Publish\Core\Repository\Values\User\User;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ManageUserRolesCommand  extends ContainerAwareCommand {

    protected function configure(){
        $this->setName( 'gazofnaz:manage_user_roles' )
             ->setDefinition(
                    array(
                        new InputArgument( 'userId', InputArgument::REQUIRED, 'the userId to check' ),
                        new InputOption( 'listRoles','', InputOption::VALUE_NONE, 'list of roles for the current user' ),
                        new InputOption( 'addRole','', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'adds roles to the current user by locationId. RoleId is hard coded.' ),
                        new InputOption( 'removeRole','', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'removes Roles from the current user by UserRoleID' ),
                    )
                )
             ->setDescription( 'Manage user roles. Check, add & delete roles from a user')
             ->setHelp(
<<<EOT
<error>WARNING: You will update the database here. Be careful</error>
<error>WARNING: Role Removal will remove ALL roles from the given user.</error>
<info>EXAMPLE: php ezpublish/console gazofnaz:content_delivery:manage_user_roles --addRole 224 --addRole 226</info>
<info>EXAMPLE: php ezpublish/console gazofnaz:content_delivery:manage_user_roles --removeRole 1234 --removeRole 5678</info>
EOT
            );
    }

    protected function execute( InputInterface $input, OutputInterface $output ){
        /** @var $repository \eZ\Publish\API\Repository\Repository */
        $repository = $this->getContainer()->get( 'ezpublish.api.repository' );

        /** @var \eZ\Publish\API\Repository\UserService $userService */
        $userService = $repository->getUserService();

        /** @var \eZ\Publish\API\Repository\RoleService $roleService */
        $roleService = $repository->getRoleService();

        /** @var \eZ\Publish\API\Repository\LocationService $locationService */
        $locationService = $repository->getLocationService();

        /** @var \eZ\Publish\Core\MVC\ConfigResolverInterface $configReslover */
        $configResolver = $this->getContainer()->get( 'ezpublish.config.resolver' );

        /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
        $dialog = $this->getHelper( 'dialog' );

        /** @var \Closure $legacyKernel */
        $kernel = $this->getContainer()->get( 'ezpublish_legacy.kernel' );

        $adminUserId = $configResolver->getParameter( 'user_id.admin', 'gazofnaz' );
        $repository->setCurrentUser( $userService->loadUser( $adminUserId ) );

        $userId = $input->getArgument( 'userId' );

        // @todo make roleId an optional parameter
        $roleIdentifier = $configResolver->getParameter( 'role_identifier.custom_role', 'gazofnaz' );

        try{
            /** @var \eZ\Publish\API\Repository\Values\User\User $user */
            $user = $userService->loadUser( $userId );
        }
        // Should catch invalid argument exceptions, which loadUser does not seem to handle natively.
        catch( \Exception $e ){
            throw new \Exception( 'userId ' . $userId . ' could not be found.' );
        }

        $this->confirmCorrectUser( $user, $dialog, $output );

        if ( $input->getOption( 'listRoles' ) ) {
            $this->listRolesForUser( $user, $adminUserId, $kernel, $output );
            // die once finished
            return;
        }

        // adding roles to users
        if ( $locationIds = $input->getOption( 'addRole' ) ) {
            $this->addRolesToUserAfterConfirmation( $user, $roleIdentifier, $locationIds, $locationService, $roleService, $dialog, $output );
        }

        // removing roles from users
        if ( $userRoleIds = $input->getOption( 'removeRole' ) ) {
            $this->removeRolesFromUserAfterConfirmation( $user, $userRoleIds, $dialog, $output );
        }

    }

    /**
     * Prints data for provided userId and asks user to type y/n to confirm
     *
     * @param User $user
     * @param OutputInterface $output
     * @throws \Exception
     */
    private function confirmCorrectUser( User $user, DialogHelper $dialog, OutputInterface $output ){
        // print data to ensure the correct userId was passed
        $output->writeln( '<info>Processing request for user:</info>' );
        $output->writeln( '<info>Name: </info>' . $user->contentInfo->name . '(' . $user->id . ')' );
        $output->writeln( '<info>Email: </info>' .$user->email );

        // explicit confirmation from end user
        if (!$dialog->askConfirmation( $output, '<question>Is this the correct user? (y/n)</question>', false)) {
            // @todo don't be lazy.
            throw new \Exception( 'Process ended. No users changed.' );
        }
    }

    /**
     * Prints each role for the given user
     *
     * Uses legacy stack because the 5.x stack does not have all the features we need
     *
     * @param User $user
     * @param $adminUserId
     * @param \Closure $kernel
     * @param OutputInterface $output
     */
    private function listRolesForUser( User $user, $adminUserId, \Closure $kernel, OutputInterface $output ){

        $output->writeln( '<info>This user as the following Roles:</info>' );

        // the 5.x stack does not return individual Role ID's. We need them for surgical deletion.
        /** @var \eZRole[] $roleAssignments */
        $roleAssignments = $kernel()->runCallback(
            function() use( $user, $adminUserId ) {

                // login as admin for legacy kernel
                \eZUser::setCurrentlyLoggedInUser( \eZUser::fetch( $adminUserId ), $adminUserId );

                // fetchByUser accepts array of id's. We only want one
                $roleAssignments = \eZRole::fetchByUser( array( $user->id ) );
                return $roleAssignments;
            },
            false
        );

        if( !empty( $roleAssignments ) ){
            // nice print of details
            foreach ( $roleAssignments as $role ) {
                $output->write( 'UserRoleID: ' );
                $output->write( $role->UserRoleID );
                $output->write( ' -> ' );
                $output->write( $role->Name );
                $output->write( ' -> ' );
                $output->write( $role->LimitValue );
                $output->writeln( ' (' . $role->LimitIdentifier . ')' );
            }
        }
        else {
            $output->writeln( '<error>This user has no roles</error>' );
        }

    }

    /**
     * Print notice of the roles to be added, and asks for explicit consent from the user.
     *
     * Takes RoleID as parameter to allow the code to work for multiple roles
     *
     * @param User $user
     * @param String $roleIdentifier
     * @param Array $locationIds
     * @param RoleService $roleService
     * @param DialogHelper $dialog
     * @param OutputInterface $output
     * @throws \Exception
     */
    private function addRolesToUserAfterConfirmation( User $user, $roleIdentifier, $locationIds, LocationService $locationService,  RoleService $roleService, DialogHelper $dialog, OutputInterface $output ){

        try{
            $role = $roleService->loadRoleByIdentifier( $roleIdentifier );
        }
        catch( \Exception $e ){
            $output->writeln( $e->getMessage() );
            throw new \Exception( 'Could not load Role with given identifier. No users changed.' );
        }

        $output->writeln( '<info>You are about to add the following roles to this user:</info>' );

        foreach( $locationIds as $locationId ){
            $output->write( $role->identifier );
            $output->write( ' -> ' );
            $output->write( $locationId );
            $output->writeln( ' (Subtree limitation)' );
        }

        // explicit confirmation from end user
        if (!$dialog->askConfirmation( $output, '<question>Are you sure you wish to continue? (y/n)</question>', false)) {
            // @todo don't be lazy.
            throw new \Exception( 'Process ended. No users changed.' );
        }

        foreach( $locationIds as $locationId ){
            $this->assignRoleToUser( $user, $locationId, $role, $locationService, $roleService, $output );
        }
    }

    /**
     * Adds a Role with limitation to a user.
     *
     * Only supports subtree limitation. Role ID passed as integer
     *
     * @param User $user
     * @param String $locationId
     * @param $role
     * @param RoleService $roleService
     * @param OutputInterface $output
     */
    private function assignRoleToUser( User $user, $locationId, $role, LocationService $locationService, RoleService $roleService, OutputInterface $output ){

        try{
            $location = $locationService->loadLocation( $locationId );
            $limitation = new SubtreeLimitation( array( 'limitationValues' => array( $location->pathString ) ) );
            $roleService->assignRoleToUser( $role, $user, $limitation );
            $output->writeln( 'added role ' . $location->pathString );
        }
        catch( \Exception $e ){
            $output->writeln( $e->getMessage() );
        }

    }

    /**
     * Surgically removes roles of the given Id from the user.
     *
     * Ez does not support removal of a single subtree through code, hence the sql
     *
     * @see https://jira.ez.no/browse/EZP-23265
     *
     * @param User $user
     * @param Array $userRoleIds
     * @param DialogHelper $dialog
     * @param OutputInterface $output
     * @throws \Exception
     */
    private function removeRolesFromUserAfterConfirmation( User $user, $userRoleIds, DialogHelper $dialog, OutputInterface $output){

        $count = count( $userRoleIds );

        $output->writeln( sprintf( '<error>You are about to delete %s roles from this user</error>', $count  ) );

        // explicit confirmation from end user
        if (!$dialog->askConfirmation( $output, '<question>Are you sure you wish to continue? (y/n)</question>', false)) {
            // @todo don't be lazy.
            throw new \Exception( 'Process ended. No users changed.' );
        }

        /** @var \eZ\Publish\Core\Persistence\Legacy\EzcDbHandler $dbhandler */
        $dbhandler = $this->getContainer()->get( 'ezpublish.api.storage_engine.legacy.dbhandler' );

        // @todo a little inefficient
        foreach( $userRoleIds as $userRoleId ){
            /** @see eZRole->removeUserAssignmentByID in legacy.*/
            $sql = "DELETE FROM ezuser_role WHERE id='$userRoleId' AND contentobject_id='$user->id'";
            $dbhandler->getDbHandler()->exec( $sql );
        }

    }
}
