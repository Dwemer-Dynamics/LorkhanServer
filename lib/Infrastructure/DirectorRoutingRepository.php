<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;

/** Resolve only the dedicated global Director connector; an empty or disabled role never falls back. */
final class DirectorRoutingRepository
{
    public function __construct(private readonly PDO $db) {}

    public function route(string $installation): ?array
    {
        $global=(new ProductRepository($this->db))->globalSettingsForInstallation($installation)['content']??[];
        if (($global['task_availability']['director']??true)!==true) return null;
        $id=$global['system_routing']['director_configuration_id']??'';
        if (!is_string($id) || !Uuid::isValid($id)) return null;
        $query=$this->db->prepare("SELECT configuration_id,current_revision FROM configuration_sets "
            ."WHERE installation_id=:installation AND configuration_id=:id AND kind='provider' AND deleted_at IS NULL");
        $query->execute(['installation'=>$installation,'id'=>$id]);
        $row=$query->fetch();
        return $row ? ['configuration_id'=>(string)$row['configuration_id'],'current_revision'=>(int)$row['current_revision']] : null;
    }
}
