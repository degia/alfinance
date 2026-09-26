export type Category = {
    id: number;
    name: string;
    icon: string;
    color: string;
    parent_id: number | null;
};

/**
 * Node tree yang dikirim controller: setiap kategori utama membawa sub-kategori
 * di `children`. Sub-kategori tidak punya `children` sendiri karena depth
 * dibatasi dua level, jadi tipenya `Category` biasa, bukan rekursif.
 */
export type CategoryNode = Category & {
    children: Category[];
};

export type CategoryParentOption = {
    id: number;
    name: string;
};

export type CategoryIconOption = {
    value: string;
    label: string;
};

export type CategoryFormData = {
    name: string;
    parent_id: string;
    icon: string;
    color: string;
};
