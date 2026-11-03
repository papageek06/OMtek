import type { User } from '../../api/client'

const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as const

/** Droits fins attribuables par un admin (miroir de User::PERMISSIONS cote API). Les admins les ont tous. */
export const PERMISSION_OPTIONS = [
  { value: 'ROLE_PERM_PIECES', label: 'Stocks globaux & pièces', description: 'Catalogue pièces, modèles, liaison pièce-modèle, stock général' },
  { value: 'ROLE_PERM_CONTRATS', label: 'Contrats & facturation', description: 'Contrats, lignes de contrat, périodes de facturation' },
  { value: 'ROLE_PERM_SITES', label: 'Sites & imprimantes', description: 'Suppression de site, contacts du site, réinstallation, CSV Alert' },
] as const

export type Permission = (typeof PERMISSION_OPTIONS)[number]['value']

export function hasRole(user: Pick<User, 'roles'> | null | undefined, role: string): boolean {
  return !!user?.roles?.includes(role)
}

export function isAdmin(user: Pick<User, 'roles'> | null | undefined): boolean {
  return !!user?.roles?.some((role) => ADMIN_ROLES.includes(role as typeof ADMIN_ROLES[number]))
}

export function hasPermission(user: Pick<User, 'roles'> | null | undefined, permission: Permission): boolean {
  return isAdmin(user) || hasRole(user, permission)
}

export function canSeeAdminStock(user: Pick<User, 'roles'> | null | undefined): boolean {
  return isAdmin(user)
}

export function canManageContracts(user: Pick<User, 'roles'> | null | undefined): boolean {
  return hasPermission(user, 'ROLE_PERM_CONTRATS')
}

export function canManagePieces(user: Pick<User, 'roles'> | null | undefined): boolean {
  return hasPermission(user, 'ROLE_PERM_PIECES')
}

export function canManageSites(user: Pick<User, 'roles'> | null | undefined): boolean {
  return hasPermission(user, 'ROLE_PERM_SITES')
}

export function canManageUsers(user: Pick<User, 'roles'> | null | undefined): boolean {
  return isAdmin(user)
}
