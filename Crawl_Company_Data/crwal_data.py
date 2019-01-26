#coding=utf-8
import  urllib3
from bs4 import BeautifulSoup
import json
import xlwt
import xlrd
def crawl_page():
    company_code = []
    people_name = []
    people_title = []
    people_resume = []
    count = 0
    with open('code.txt','r') as f:
        for code in f.readlines():
            print(count)
            code = code.rstrip('\n')
            url = "http://s.askci.com/stock/executives/"+str(code)+'/'
            http = urllib3.PoolManager()
            r = http.request('GET',url)
            html = r.data.decode()
            soup = BeautifulSoup(html,'lxml')
            name, title, resume = page_parse(soup,code)
            if len(name)==0:
                pass
            else:
                company_code.append(code)
                people_name.append(name)
                people_title.append(title)
                people_resume.append(resume)
            count+= 1
    data = {'company_code':company_code,'people_name':people_name,'people_title':people_title,'people_resume':people_resume}
    with open("record.json", "w") as f:
        json.dump(data, f,ensure_ascii =False)

def page_parse(soup,code):
    #考虑出现多个董事长秘书
    all_name = []
    all_title = []
    all_resume = []
    content = soup.find_all(name='div',attrs={'class':'right_f_d_table mg_tone'})[-1]
    for tr in content.find_all('tr') :
        if tr.find_all('td')[2].get_text().find('董事会秘书') >=0:
            print(code)
            name = tr.find_all('td')[1].get_text()
            all_name.append(name)
            print(name)
            title = tr.find_all('td')[2].get_text()
            all_title.append(title)
            print(title)
            resume = tr.find_all('td')[10].get_text()
            all_resume.append(resume)
            print(resume)
    return all_name,all_title,all_resume


if __name__ == '__main__':
    crawl_page()
    #
    url = "http://s.askci.com/stock/xsb/"
    http = urllib3.PoolManager()
    with open('code.txt','a+') as f:
    for i in range(1,537):
    	word = {"reportTime": "2018-09-30", 'pageNum':i}
        r = http.request('GET', url,fields=word)
        html = r.data.decode()
        soup = BeautifulSoup(html, 'lxml')
    	content = soup.find_all(name='table')[3]
		for tr in content.find_all('tr'):
			target = tr.find_all('a')[0].get_text()
			if len(target) ==6:
				f.writelines(target+'\n')

    with open("record.json",'r') as f:
        data = json.load(f)
    company_code = data['company_code']
    people_name = data['people_name']
    people_title = data['people_title']
    people_resume = data['people_resume']
    # 创建excel文件,给工作表命名，info
    filename = xlwt.Workbook()
    sheet = filename.add_sheet("info")
    for i in range(len(company_code)):
        sheet.write(i,0,company_code[i])
    for i in range(len(people_name)):
        sheet.write(i,1,people_name[i][0])
    for i in range(len(people_title)):
        sheet.write(i,2,people_title[i][0])
    for i in range(len(people_resume)):
        sheet.write(i,3,people_resume[i][0])
    filename.save("info.xls")

